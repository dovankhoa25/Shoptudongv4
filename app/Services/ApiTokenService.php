<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

class ApiTokenService
{
    public function revokeToken(Token $token, ?UserSession $session = null, string $reason = 'logout'): void
    {
        DB::transaction(function () use ($token, $session, $reason): void {
            Passport::token()->newQuery()
                ->whereKey($token->id)
                ->update(['revoked' => true]);

            Passport::refreshToken()->newQuery()
                ->where('access_token_id', $token->id)
                ->update(['revoked' => true]);

            UserSession::query()
                ->where('oauth_access_token_id', $token->id)
                ->when($session, fn ($query) => $query->whereKey($session->id))
                ->update([
                    'is_revoked' => true,
                    'revoked_at' => now(),
                    'revoked_reason' => $reason,
                ]);

        });
    }

    public function revokeAll(User $user, string $reason): int
    {
        return DB::transaction(function () use ($user, $reason): int {
            $tokenIds = $user->tokens()
                ->where('revoked', false)
                ->lockForUpdate()
                ->pluck('id');

            Passport::token()->newQuery()
                ->whereIn('id', $tokenIds)
                ->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);

            $revokedSessions = UserSession::query()
                ->where('user_id', $user->id)
                ->where('is_revoked', false)
                ->update([
                    'is_revoked' => true,
                    'revoked_at' => now(),
                    'revoked_reason' => $reason,
                ]);

            return $revokedSessions;
        });
    }

    public function revokeClientTokens(string $clientId, string $reason): int
    {
        return DB::transaction(function () use ($clientId, $reason): int {
            $tokens = Passport::token()->newQuery()
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->get(['id', 'revoked']);
            $tokenIds = $tokens->pluck('id');

            if ($tokenIds->isNotEmpty()) {
                Passport::token()->newQuery()
                    ->whereIn('id', $tokenIds)
                    ->update(['revoked' => true]);
                Passport::refreshToken()->newQuery()
                    ->whereIn('access_token_id', $tokenIds)
                    ->update(['revoked' => true]);
            }

            UserSession::query()
                ->where('oauth_client_id', $clientId)
                ->where('is_revoked', false)
                ->update([
                    'is_revoked' => true,
                    'revoked_at' => now(),
                    'revoked_reason' => $reason,
                ]);

            return $tokens->where('revoked', false)->count();
        });
    }
}
