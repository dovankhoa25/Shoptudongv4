<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAuthProvider;
use App\Models\UserSecurityLog;
use App\Services\FirstPartyClientService;
use App\Services\FirstPartyTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function __construct(
        private readonly FirstPartyClientService $clients,
        private readonly FirstPartyTokenService $tokens,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:191', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'client_id' => ['required', 'uuid'],
        ]);

        $this->clients->validate($request, $validated['client_id']);
        $this->tokens->ensureConfigured();

        [$user, $authorization] = DB::transaction(function () use ($validated, $request): array {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'status' => User::STATUS_ACTIVE,
            ]);

            UserAuthProvider::create([
                'user_id' => $user->id,
                'provider' => 'password',
                'provider_id' => $user->username,
                'provider_email' => $user->email,
                'provider_username' => $user->username,
                'last_login_at' => now(),
            ]);

            UserSecurityLog::create([
                'user_id' => $user->id,
                'event' => 'register_success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'meta' => ['provider' => 'password'],
            ]);

            return [
                $user,
                $this->tokens->issue(
                    $validated['username'],
                    $validated['password'],
                ),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Đăng Kí Thành Công.',
            'user' => $user,
            'authorization' => $authorization,
        ], 201);
    }
}
