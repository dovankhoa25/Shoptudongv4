<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserSecurityLog;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Token;

class LogoutController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    // public function __invoke(Request $request): JsonResponse
    // {
    //     $token = $request->user()->currentAccessToken();

    //     if ($token instanceof Token) {
    //         $this->tokens->revokeToken($token);
    //     }

    //     UserSecurityLog::create([
    //         'user_id' => $request->user()->id,
    //         'event' => 'logout',
    //         'ip_address' => $request->ip(),
    //         'user_agent' => $request->userAgent(),
    //         'meta' => ['channel' => 'api'],
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Logout successful.',
    //     ]);
    // }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->tokens->revokeAll($user, 'logout');

        UserSecurityLog::create([
            'user_id' => $user->id,
            'event' => 'logout',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['channel' => 'api'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
        ]);
    }
}
