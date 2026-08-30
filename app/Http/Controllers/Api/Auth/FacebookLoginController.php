<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSecurityLog;
use App\Services\FacebookAuthService;
use App\Services\FirstPartyClientService;
use App\Services\FirstPartyUserTokenService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;

class FacebookLoginController extends Controller
{
    public function __construct(
        private readonly FirstPartyClientService $clients,
        private readonly FirstPartyUserTokenService $tokens,
        private readonly FacebookAuthService $facebook,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string'],
            'provider' => ['sometimes', 'string', 'in:facebook'],
            'client_id' => ['required', 'uuid'],
        ]);

        $this->clients->validate($request, $validated['client_id']);

        try {
            $facebookUser = $this->facebook->verifyAccessToken($validated['access_token']);
        } catch (ConnectionException $exception) {
            Log::warning('Facebook login is temporarily unavailable', [
                'exception' => $exception::class,
            ]);
            $this->recordAttempt($request, null, false, 'facebook_unavailable');

            return response()->json([
                'success' => false,
                'message' => 'Máy chủ hiện không thể kết nối đến Facebook. Vui lòng thử lại sau.',
            ], 503);
        } catch (LogicException) {
            return response()->json([
                'success' => false,
                'message' => 'Đăng nhập Facebook chưa được cấu hình trên máy chủ.',
            ], 500);
        }

        if (! $facebookUser) {
            $this->recordAttempt($request, null, false, 'invalid_facebook_token');

            return response()->json([
                'success' => false,
                'message' => 'Token Facebook không hợp lệ hoặc đã hết hạn.',
            ], 401);
        }

        $user = $this->facebook->resolveAccessTokenUser($facebookUser, $request);
        $authorization = $this->tokens->issue($user->getKey());

        $this->recordAttempt($request, $user, true, email: $user->email);
        UserSecurityLog::create([
            'user_id' => $user->id,
            'event' => 'login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['provider' => 'facebook', 'channel' => 'api'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập Facebook thành công.',
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
            'provider' => 'facebook',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_success' => $success,
            'failure_reason' => $failureReason,
        ]);
    }
}
