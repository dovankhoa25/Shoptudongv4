<?php

namespace App\Services;

use App\Models\User;
use Laravel\Passport\Passport;

class AccountRefreshTokenRepository extends \Laravel\Passport\Bridge\RefreshTokenRepository
{
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $refresh = Passport::refreshToken()->newQuery()->with('accessToken')->find($tokenId);
        if (! $refresh || $refresh->revoked || ! $refresh->accessToken || $refresh->accessToken->revoked) {
            return true;
        }

        // Also covers tokens issued before the ban enforcement patch was deployed.
        $user = User::find($refresh->accessToken->user_id);

        return ! $user || $user->isLocked();
    }
}
