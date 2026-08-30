<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSecurityLog;
use App\Services\FirstPartyClientService;
use App\Services\FirstPartyUserTokenService;
use App\Services\GoogleAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleLoginController extends Controller
{
    public function __construct(
        private readonly FirstPartyClientService $clients,
        private readonly FirstPartyUserTokenService $tokens,
        private readonly GoogleAuthService $google,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string'],
            'provider' => ['sometimes', 'string', 'in:google'],
            'client_id' => ['required', 'uuid'],
        ]);

        $this->clients->validate($request, $validated['client_id']);

        try {
            $googleUser = $this->google->verifyAccessToken($validated['access_token']);
        } catch (ConnectionException $exception) {
            Log::warning('Google login is temporarily unavailable', [
                'exception' => $exception::class,
            ]);

            $this->recordAttempt($request, null, false, 'google_unavailable');

            return response()->json([
                'success' => false,
                'message' => 'Máy chủ hiện không thể kết nối đến Google. Vui lòng thử lại sau.',
            ], 503);
        }

        if (! $googleUser) {
            $this->recordAttempt($request, null, false, 'invalid_google_token');

            return response()->json([
                'success' => false,
                'message' => 'Token Google không hợp lệ hoặc đã hết hạn.',
            ], 401);
        }

        if (! $googleUser['email_verified']) {
            $this->recordAttempt($request, null, false, 'google_email_unverified', $googleUser['email']);

            return response()->json([
                'success' => false,
                'message' => 'Email chưa được Google xác thực.',
            ], 422);
        }

        $user = $this->google->resolveAccessTokenUser($googleUser, $request);
        $authorization = $this->tokens->issue($user->getKey());

        $this->recordAttempt($request, $user, true, email: $user->email);
        UserSecurityLog::create([
            'user_id' => $user->id,
            'event' => 'login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['provider' => 'google', 'channel' => 'api'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập Google thành công.',
            'user' => $user,
            'authorization' => $authorization,
        ]);
    }

    private function recordAttempt(
        Request $request,
        ?User $user,
        bool $success,
        ?string $failureReason = null,
        ?string $email = null,
    ): void {
        LoginAttempt::create([
            'user_id' => $user?->id,
            'email' => $email,
            'provider' => 'google',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_success' => $success,
            'failure_reason' => $failureReason,
        ]);
    }
}
