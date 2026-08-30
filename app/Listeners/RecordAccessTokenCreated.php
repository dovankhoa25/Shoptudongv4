<?php

namespace App\Listeners;

use App\Models\UserSecurityLog;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

class RecordAccessTokenCreated
{
    public function __construct(private readonly Request $request)
    {
    }

    public function handle(AccessTokenCreated $event): void
    {
        if (! $event->userId) {
            return;
        }

        $token = Passport::token()->newQuery()->find($event->tokenId);

        $session = UserSession::updateOrCreate(
            ['oauth_access_token_id' => $event->tokenId],
            [
                'user_id' => $event->userId,
                'oauth_client_id' => $event->clientId,
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->request->userAgent(),
                'last_activity_at' => now(),
                'expires_at' => $token?->expires_at,
            ],
        );

        UserSecurityLog::create([
            'user_id' => $event->userId,
            'event' => 'api_token_issued',
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'meta' => [
                'session_id' => $session->id,
                'client_id' => $event->clientId,
                'application_client_id' => $this->request->input('client_id'),
            ],
        ]);
    }
}
