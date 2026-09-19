<?php

namespace App\Services;

use App\Models\AdminAccessDevice;
use App\Models\LoginAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserSecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAccessService
{
    public const APPROVAL_SETTING = 'admin_access_approval_required';

    public function approvalRequired(): bool
    {
        // Use the existing shared settings cache; do not memoize across worker requests.
        $default = config('access_security.admin_approval_required', true);
        try {
            $value = Setting::get(self::APPROVAL_SETTING, $default);
        } catch (\Throwable) {
            // A cache outage must not switch off the policy or prevent a DB fallback.
            $value = Setting::query()->useWritePdo()->where('key', self::APPROVAL_SETTING)->value('value') ?? $default;
        }

        // Only an explicit saved zero (or the boolean config default) disables approval.
        // Empty/malformed values must not accidentally switch off access protection.
        return ! in_array($value, [false, 0, '0'], true);
    }

    public function setApprovalRequired(bool $enabled, ?Request $request = null): void
    {
        $previous = $this->approvalRequired();
        $actor = $request?->user();
        DB::transaction(function () use ($enabled, $previous, $request, $actor): void {
            // The authorized operator explicitly trusts this browser when enabling the policy.
            // Other browsers/sessions remain subject to approval on their next request.
            if ($enabled && $actor && ! $this->approved($actor, $request)) {
                $device = AdminAccessDevice::firstOrCreate([
                    'user_id' => $actor->id, 'device_hash' => $this->deviceHash($request, true),
                    'ip_address' => $request->ip(),
                ], ['user_agent' => Str::limit((string) $request->userAgent(), 1000, ''), 'last_seen_at' => now()]);
                $this->approve($device, $actor);
            }
            Setting::set(self::APPROVAL_SETTING, $enabled ? '1' : '0');
            UserSecurityLog::create([
                'user_id' => $actor?->id, 'event' => 'admin_access_policy_changed',
                'ip_address' => $request?->ip(),
                'meta' => ['enabled' => $enabled, 'previous_enabled' => $previous,
                    'actor_id' => $actor?->id, 'source' => $actor ? 'admin' : 'console'],
            ]);
        });
    }

    public function applies(User $user): bool
    {
        return $this->approvalRequired()
            && ($user->roleLevel() > 0 || $user->getAllPermissions()->isNotEmpty()
                || in_array((string) $user->id, config('sso.admin_user_ids', []), true));
    }

    public function deviceHash(Request $request, bool $enroll = false): ?string
    {
        $name = config('access_security.device_cookie');
        $token = $request->cookie($name);
        if (! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            if (! $enroll) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            Cookie::queue(Cookie::make($name, $token, 60 * 24 * (int) config('access_security.device_days'),
                '/', null, (bool) config('session.secure') || app()->isProduction(), true, false, 'lax'));
        }

        return hash('sha256', $token);
    }

    public function approved(User $user, Request $request): bool
    {
        return $this->approvedDevice($user, $request) !== null;
    }

    public function approvedDevice(User $user, Request $request): ?AdminAccessDevice
    {
        $hash = $this->deviceHash($request);
        if ($hash === null) {
            return null;
        }

        return AdminAccessDevice::where('user_id', $user->id)->where('device_hash', $hash)
            ->where('ip_address', $request->ip())->where('status', 'approved')
            ->where('expires_at', '>', now())->first();
    }

    // Called only AFTER the password/provider identity has been verified.
    public function authorizeLogin(User $user, Request $request, string $provider): void
    {
        if (! $this->applies($user)) {
            return;
        }
        if ($this->approved($user, $request)) {
            AdminAccessDevice::where('user_id', $user->id)->where('device_hash', $this->deviceHash($request))
                ->where('ip_address', $request->ip())->update(['last_seen_at' => now()]);

            return;
        }
        $device = AdminAccessDevice::firstOrCreate([
            'user_id' => $user->id,
            'device_hash' => $this->deviceHash($request, true),
            'ip_address' => $request->ip(),
        ], ['user_agent' => Str::limit((string) $request->userAgent(), 1000, ''), 'last_seen_at' => now()]);
        $device->update(['last_seen_at' => now()]);
        if ($device->status === 'approved' && (! $device->expires_at || $device->expires_at->isPast())) {
            $device->update(['status' => 'pending']);
        }
        LoginAttempt::create([
            'user_id' => $user->id, 'username' => $user->username, 'provider' => $provider,
            'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'is_success' => false, 'failure_reason' => 'admin_access_pending',
            'meta' => ['channel' => 'web', 'access_request_id' => $device->id,
                'ip_source' => $request->attributes->get('security_ip_source', 'peer')],
        ]);
        UserSecurityLog::create([
            'user_id' => $user->id, 'event' => 'admin_access_pending', 'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'meta' => ['access_request_id' => $device->id, 'channel' => 'web'],
        ]);
        throw ValidationException::withMessages([
            'username' => 'IP hoặc thiết bị quản trị chưa được duyệt (yêu cầu #'.$device->id.'). Vui lòng chờ người quản trị duyệt rồi đăng nhập lại.',
        ]);
    }

    public function approve(AdminAccessDevice $device, ?User $actor): void
    {
        abort_if($device->user->isLocked(), 422, 'Tài khoản đang bị khóa; cần mở tài khoản trước.');
        $device->update(['status' => 'approved', 'approved_by' => $actor?->id,
            'approved_at' => now(), 'expires_at' => now()->addDays((int) config('access_security.device_days'))]);
        $this->audit($device, $actor, 'admin_access_approved');
    }

    public function revoke(AdminAccessDevice $device, ?User $actor): void
    {
        $device->update(['status' => 'revoked']);
        $this->audit($device, $actor, 'admin_access_revoked');
    }

    private function audit(AdminAccessDevice $device, ?User $actor, string $event): void
    {
        UserSecurityLog::create(['user_id' => $device->user_id, 'event' => $event,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'meta' => ['access_request_id' => $device->id, 'actor_id' => $actor?->id,
                'source' => $actor ? 'admin' : 'console']]);
    }
}
