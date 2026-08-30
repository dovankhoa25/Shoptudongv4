<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSecurityLog;
use App\Services\FirstPartyClientService;
use App\Services\FirstPartyTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(
        private readonly FirstPartyClientService $clients,
        private readonly FirstPartyTokenService $tokens,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'client_id' => ['required', 'uuid'],
        ]);

        $this->clients->validate($request, $validated['client_id']);
        $this->tokens->ensureConfigured();

        $user = User::query()
            ->where('username', $validated['login'])
            ->orWhere('email', $validated['login'])
            ->first();

        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            $this->recordAttempt($request, $user, false, 'invalid_credentials');

            throw ValidationException::withMessages([
                'login' => 'Không Đúng username, email, or password.',
            ]);
        }

        if ($user->isLocked()) {
            $this->recordAttempt($request, $user, false, 'user_locked');

            return response()->json([
                'success' => false,
                'message' => 'This account is locked.',
                'locked_reason' => $user->locked_reason,
                'locked_until' => $user->locked_until,
            ], 423);
        }

        $provider = $user->authProviders()->where('provider', 'password')->first();

        if ($provider && ! $provider->is_enabled) {
            $this->recordAttempt($request, $user, false, 'provider_disabled');

            return response()->json([
                'success' => false,
                'message' => 'Password login is disabled for this account.',
            ], 403);
        }

        $authorization = $this->tokens->issue(
            $validated['login'],
            $validated['password'],
        );

        $provider?->update(['last_login_at' => now()]);
        $this->recordAttempt($request, $user, true);

        UserSecurityLog::create([
            'user_id' => $user->id,
            'event' => 'login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['provider' => 'password', 'channel' => 'api'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đăng Nhập Thành Công.',
            'user' => $user,
            'authorization' => $authorization,
        ]);
    }

    private function recordAttempt(
        Request $request,
        ?User $user,
        bool $success,
        ?string $failureReason = null,
    ): void {
        LoginAttempt::create([
            'user_id' => $user?->id,
            'username' => $request->input('login'),
            'email' => filter_var($request->input('login'), FILTER_VALIDATE_EMAIL) ?: null,
            'provider' => 'password',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_success' => $success,
            'failure_reason' => $failureReason,
        ]);
    }
}
