<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserSecurityLog;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens)
    {
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        $user->update(['password' => $validated['password']]);
        $revokedSessions = $this->tokens->revokeAll($user, 'password_changed');

        UserSecurityLog::create([
            'user_id' => $user->id,
            'event' => 'password_changed',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['revoked_sessions' => $revokedSessions],
        ]);

        return response()->json([
            'message' => 'Password updated. Please log in again.',
            'revoked_sessions' => $revokedSessions,
        ]);
    }
}
