<?php

namespace App\Listeners;

use App\Models\UserSession;
use Laravel\Passport\Events\AccessTokenRevoked;

class RecordAccessTokenRevoked
{
    public function handle(AccessTokenRevoked $event): void
    {
        UserSession::query()
            ->where('oauth_access_token_id', $event->tokenId)
            ->where('is_revoked', false)
            ->update([
                'is_revoked' => true,
                'revoked_at' => now(),
                'revoked_reason' => 'oauth_token_revoked',
            ]);
    }
}
