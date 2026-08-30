<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Profile\SessionResource;
use App\Models\UserSecurityLog;
use App\Models\UserSession;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $sessions = $request->user()
            ->sessions()
            ->with('device:id,device_id,device_name,platform,browser,last_seen_at')
            ->latest('last_activity_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => SessionResource::collection($sessions),
        ]);
    }

    public function destroy(Request $request, UserSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        $token = $request->user()->tokens()->find($session->oauth_access_token_id);

        if ($token) {
            $this->tokens->revokeToken($token, $session, 'user_revoked');
        } else {
            $session->update([
                'is_revoked' => true,
                'revoked_at' => now(),
                'revoked_reason' => 'user_revoked',
            ]);
        }

        UserSecurityLog::create([
            'user_id' => $request->user()->id,
            'event' => 'session_revoked',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['session_id' => $session->id],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session revoked.',
        ]);
    }
}
