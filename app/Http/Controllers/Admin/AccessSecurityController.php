<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessIpBlock;
use App\Models\AdminAccessDevice;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSecurityLog;
use App\Models\UserSession;
use App\Services\AdminAccessService;
use App\Support\IpNetwork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

class AccessSecurityController extends Controller
{
    public function history(Request $request, User $user)
    {
        $data = $request->validate(['ip' => ['nullable', 'ip'], 'page' => ['nullable', 'integer', 'min:1']]);
        $attempts = LoginAttempt::where('user_id', $user->id)
            ->when($data['ip'] ?? null, fn ($q, $ip) => $q->where('ip_address', $ip))
            ->latest('id')->paginate(20, ['id', 'username', 'provider', 'ip_address', 'user_agent',
                'is_success', 'failure_reason', 'created_at', 'meta']);
        // Return an explicit metadata allowlist, never session IDs or credentials.
        $attempts->getCollection()->transform(function ($row) {
            $row->meta = array_intersect_key($row->meta ?? [], array_flip(['channel', 'ip_source', 'access_request_id']));

            return $row;
        });

        return response()->json([
            'attempts' => $attempts,
            'ips' => LoginAttempt::where('user_id', $user->id)->whereNotNull('ip_address')
                ->selectRaw('ip_address, COUNT(*) as total, SUM(CASE WHEN is_success = 1 THEN 1 ELSE 0 END) as successes, MIN(created_at) as first_seen, MAX(created_at) as last_seen')
                ->groupBy('ip_address')->orderByDesc('last_seen')->limit(100)->get(),
            'events' => UserSecurityLog::where('user_id', $user->id)->latest('id')->limit(50)
                ->get(['id', 'event', 'ip_address', 'created_at']),
            'sessions' => UserSession::where('user_id', $user->id)->latest('id')->limit(50)
                ->get(['id', 'ip_address', 'user_agent', 'last_activity_at', 'is_revoked', 'revoked_at', 'revoked_reason', 'created_at']),
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,revoked'],
            'page' => ['nullable', 'integer', 'min:1'], 'block_page' => ['nullable', 'integer', 'min:1']]);

        return response()->json([
            'devices' => AdminAccessDevice::with('user:id,username')
                ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->latest('id')->paginate(20),
            'blocks' => AccessIpBlock::whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')->paginate(20, ['*'], 'block_page'),
            'current_ip' => $request->ip(),
            'ip_source' => $request->attributes->get('security_ip_source', 'peer'),
        ]);
    }

    public function approve(AdminAccessDevice $device, AdminAccessService $service)
    {
        $service->approve($device, request()->user());

        return response()->json(['message' => 'Đã duyệt IP và thiết bị. Người dùng cần đăng nhập lại.']);
    }

    public function revoke(AdminAccessDevice $device, AdminAccessService $service)
    {
        $service->revoke($device, request()->user());

        return response()->json(['message' => 'Đã thu hồi; phiên quản trị sẽ bị chặn ở yêu cầu tiếp theo.']);
    }

    public function block(Request $request)
    {
        $data = $request->validate([
            'network' => ['required', 'string', 'max:49'], 'reason' => ['required', 'string', 'max:500'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);
        $network = IpNetwork::normalize($data['network']);
        if (IpUtils::checkIp($request->ip(), $network)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['network' => 'Dải này chứa IP bạn đang dùng. Hãy dùng kết nối quản trị khác để tránh tự khóa.']);
        }
        $block = DB::transaction(function () use ($request, $network, $data) {
            $block = AccessIpBlock::create([
                'network' => $network, 'reason' => $data['reason'], 'created_by' => $request->user()->id,
                'expires_at' => isset($data['hours']) ? now()->addHours($data['hours']) : null,
            ]);
            $this->audit($request, 'ip_range_blocked', $block);

            return $block;
        });

        return response()->json(['message' => 'Đã chặn IP/dải IP.', 'block' => $block], 201);
    }

    public function unblock(Request $request, AccessIpBlock $block)
    {
        DB::transaction(function () use ($request, $block): void {
            $block->update(['revoked_at' => now()]);
            $this->audit($request, 'ip_range_unblocked', $block);
        });

        return response()->json(['message' => 'Đã mở chặn IP/dải IP.']);
    }

    private function audit(Request $request, string $event, AccessIpBlock $block): void
    {
        UserSecurityLog::create(['user_id' => $request->user()->id, 'event' => $event,
            'ip_address' => $request->ip(), 'meta' => ['block_id' => $block->id, 'network' => $block->network]]);
    }
}
