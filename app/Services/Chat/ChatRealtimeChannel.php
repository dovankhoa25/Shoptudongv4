<?php

namespace App\Services\Chat;

use App\Models\ChatRealtimeSession;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use LogicException;
use Throwable;

class ChatRealtimeChannel
{
    private const REQUEST_CHANNEL_ATTRIBUTE = '_chat_realtime_channel';

    public static function name(int $userId, string $credential): string
    {
        return "Chat.User.{$userId}.{$credential}";
    }

    public function currentForRequest(Request $request, bool $refresh = false): ?string
    {
        if (! $refresh && $request->attributes->has(self::REQUEST_CHANNEL_ATTRIBUTE)) {
            $channel = $request->attributes->get(self::REQUEST_CHANNEL_ATTRIBUTE);

            return is_string($channel) ? $channel : null;
        }

        $user = $request->user();

        if ($refresh && $user instanceof User) {
            $accessToken = $user->token();
            $user = User::query()->find($user->getKey());
            $user?->withAccessToken($accessToken);
        }

        if (! $user instanceof User || $user->isLocked()) {
            $request->attributes->set(self::REQUEST_CHANNEL_ATTRIBUTE, false);

            return null;
        }

        $accessToken = $user->token();

        if ($accessToken !== null) {
            $channel = $this->apiChannel($user, $accessToken);
        } else {
            $channel = $this->webChannel($request, $user);
        }

        $request->attributes->set(self::REQUEST_CHANNEL_ATTRIBUTE, $channel ?? false);

        return $channel;
    }

    public function webCredentialHash(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        return $sessionId !== '' ? $this->digest("web:{$sessionId}") : null;
    }

    public function revokeWebCredential(int $userId, string $credentialHash): void
    {
        ChatRealtimeSession::query()
            ->where('user_id', $userId)
            ->where('credential_hash', $credentialHash)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Resolve every active credential at publish time. Expired Passport tokens
     * and expired web leases disappear from the target list without relying on
     * a client disconnect or timer.
     *
     * @param  list<int>  $userIds
     * @return list<PrivateChannel>
     */
    public function privateChannelsFor(array $userIds): array
    {
        $ids = collect($userIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $eligibleIds = User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'status', 'locked_until'])
            ->reject(fn (User $user): bool => $user->isLocked())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($eligibleIds->isEmpty()) {
            return [];
        }

        $channels = ChatRealtimeSession::query()
            ->whereIn('user_id', $eligibleIds)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get(['user_id', 'credential_hash', 'session_locator'])
            ->filter(fn (ChatRealtimeSession $session): bool => $this->webSessionIsAuthenticated($session))
            ->map(fn (ChatRealtimeSession $session): string => self::name(
                (int) $session->user_id,
                'web-'.$session->credential_hash,
            ));

        $apiChannels = Passport::token()->newQuery()
            ->with('client:id,revoked')
            ->whereIn('user_id', $eligibleIds)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->get(['id', 'user_id', 'client_id', 'scopes', 'revoked', 'expires_at'])
            ->filter(fn (Token $token): bool => $token->client !== null
                && ! $token->client->revoked
                && $token->can('chat:read'))
            ->map(fn (Token $token): string => self::name(
                (int) $token->user_id,
                'api-'.$this->digest("api:{$token->id}"),
            ));

        return collect($channels->all())
            ->merge($apiChannels->all())
            ->unique()
            ->map(fn (string $channel): PrivateChannel => new PrivateChannel($channel))
            ->values()
            ->all();
    }

    private function apiChannel(User $user, mixed $accessToken): ?string
    {
        $tokenId = match (true) {
            $accessToken instanceof AccessToken => (string) ($accessToken->oauth_access_token_id ?? ''),
            $accessToken instanceof Token => (string) $accessToken->getKey(),
            default => '',
        };

        if ($tokenId === '') {
            return null;
        }

        $token = Passport::token()->newQuery()
            ->with('client:id,revoked')
            ->whereKey($tokenId)
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->first();

        if (! $token
            || ! $token->client
            || $token->client->revoked
            || ! $token->can('chat:read')) {
            return null;
        }

        return self::name(
            (int) $user->getKey(),
            'api-'.$this->digest("api:{$tokenId}"),
        );
    }

    private function webChannel(Request $request, User $user): ?string
    {
        $credentialHash = $this->webCredentialHash($request);

        if ($credentialHash === null) {
            return null;
        }

        $now = now();
        $expiresAt = $now->copy()->addMinutes(max(1, (int) config('session.lifetime', 120)));
        $sessionId = $request->session()->getId();

        ChatRealtimeSession::query()->insertOrIgnore([
            'user_id' => $user->getKey(),
            'credential_hash' => $credentialHash,
            'session_locator' => Crypt::encryptString($sessionId),
            'last_seen_at' => $now,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $active = ChatRealtimeSession::query()
            ->where('user_id', $user->getKey())
            ->where('credential_hash', $credentialHash)
            ->whereNull('revoked_at')
            ->update([
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'updated_at' => $now,
            ]);

        if ($active === 0) {
            return null;
        }

        return self::name(
            (int) $user->getKey(),
            'web-'.$credentialHash,
        );
    }

    private function digest(string $value): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new LogicException('APP_KEY is required for chat realtime credentials.');
        }

        return hash_hmac('sha256', $value, $key);
    }

    private function webSessionIsAuthenticated(ChatRealtimeSession $lease): bool
    {
        try {
            $sessionId = Crypt::decryptString($lease->session_locator);
            $payload = $this->readCanonicalSession($sessionId);

            if ((bool) config('session.encrypt', false) && $payload !== '') {
                $payload = app(Encrypter::class)->decrypt($payload);
            }
        } catch (Throwable) {
            return false;
        }

        if ($payload === '') {
            return false;
        }

        $attributes = config('session.serialization', 'php') === 'json'
            ? json_decode($payload, true)
            : @unserialize($payload, ['allowed_classes' => false]);

        if (! is_array($attributes)) {
            return false;
        }

        $guardKey = Auth::guard('web')->getName();

        return isset($attributes[$guardKey])
            && (int) $attributes[$guardKey] === (int) $lease->user_id;
    }

    private function readCanonicalSession(string $sessionId): string
    {
        $driver = (string) config('session.driver');

        if (in_array($driver, ['array', 'cookie'], true)) {
            return '';
        }

        if ($driver === 'database') {
            $connection = config('session.connection') ?: config('database.default');
            $payload = DB::connection($connection)
                ->table((string) config('session.table', 'sessions'))
                ->where('id', $sessionId)
                ->where('last_activity', '>=', now()
                    ->subMinutes(max(1, (int) config('session.lifetime', 120)))
                    ->getTimestamp())
                ->value('payload');

            if (! is_string($payload)) {
                return '';
            }

            return (string) (base64_decode($payload, true) ?: '');
        }

        try {
            $payload = app('session')->driver()->getHandler()->read($sessionId);
        } catch (Throwable) {
            return '';
        }

        return is_string($payload) ? $payload : '';
    }
}
