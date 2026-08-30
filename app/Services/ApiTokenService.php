<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

class ApiTokenService
{
    public function __construct(
        private readonly AccessTokenRepository $tokens,
    ) {
    }

    public function revokeToken(Token $token, ?UserSession $session = null, string $reason = 'logout'): void
    {
        $this->tokens->revokeAccessToken($token->id);
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
    }

    public function revokeAll(User $user, string $reason): int
    {
        $tokenIds = $user->tokens()
            ->where('revoked', false)
            ->pluck('id');

        foreach ($tokenIds as $tokenId) {
            $this->tokens->revokeAccessToken($tokenId);
            Passport::refreshToken()->newQuery()
                ->where('access_token_id', $tokenId)
                ->update(['revoked' => true]);
        }

        return UserSession::query()
            ->where('user_id', $user->id)
            ->where('is_revoked', false)
            ->update([
                'is_revoked' => true,
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);
    }
}
