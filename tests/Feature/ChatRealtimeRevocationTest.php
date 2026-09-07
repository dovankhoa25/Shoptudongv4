<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Events\ChatMessageSent;
use App\Models\ChatRealtimeSession;
use App\Models\User;
use App\Models\UserSession;
use App\Services\ApiTokenService;
use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatRealtimeRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('BROADCAST_CONNECTION=ably');
        putenv('ABLY_KEY=test.key:secret');
        $_ENV['BROADCAST_CONNECTION'] = 'ably';
        $_ENV['ABLY_KEY'] = 'test.key:secret';
        $_SERVER['BROADCAST_CONNECTION'] = 'ably';
        $_SERVER['ABLY_KEY'] = 'test.key:secret';

        parent::setUp();

        config()->set('session.driver', 'database');
        config()->set('broadcasting.default', 'ably');
        config()->set('broadcasting.connections.ably.key', 'test.key:secret');
        $this->app['session']->forgetDrivers();
    }

    protected function tearDown(): void
    {
        putenv('BROADCAST_CONNECTION=null');
        putenv('ABLY_KEY');
        $_ENV['BROADCAST_CONNECTION'] = 'null';
        unset($_ENV['ABLY_KEY']);
        $_SERVER['BROADCAST_CONNECTION'] = 'null';
        unset($_SERVER['ABLY_KEY']);

        parent::tearDown();
    }

    public function test_web_delivery_uses_an_active_server_side_session_lease(): void
    {
        $user = User::factory()->create();
        $channel = $this->webChannelFor($user);

        $this->assertMatchesRegularExpression(
            "/^Chat\\.User\\.{$user->id}\\.web-[a-f0-9]{64}$/",
            $channel,
        );
        $this->assertDatabaseHas('chat_realtime_sessions', [
            'user_id' => $user->id,
            'revoked_at' => null,
        ]);
        $lease = ChatRealtimeSession::query()->where('user_id', $user->id)->firstOrFail();
        $sessionId = Crypt::decryptString($lease->session_locator);
        $this->assertNotNull(DB::table('sessions')->where('id', $sessionId)->value('payload'));
        $this->assertSame(
            'private-'.$channel,
            (string) (new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn()[0],
        );
        $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '123.456',
        ])->assertOk();
    }

    public function test_web_delivery_stops_when_the_canonical_session_disappears(): void
    {
        $user = User::factory()->create();
        $this->webChannelFor($user);
        $lease = ChatRealtimeSession::query()->where('user_id', $user->id)->firstOrFail();
        $sessionId = Crypt::decryptString($lease->session_locator);

        DB::table('sessions')->where('id', $sessionId)->delete();

        $this->assertSame([], (new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn());
    }

    public function test_expired_web_lease_is_silent_until_the_still_valid_session_renews_it(): void
    {
        $user = User::factory()->create();
        $channel = $this->webChannelFor($user);
        ChatRealtimeSession::query()->where('user_id', $user->id)->update([
            'expires_at' => now()->subSecond(),
        ]);

        $this->assertSame([], (new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn());

        $this->actingAs($user)
            ->getJson('/chat/realtime-channel')
            ->assertOk()
            ->assertJsonPath('data.channel', $channel);

        $this->assertSame(
            ['private-'.$channel],
            collect((new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn())
                ->map(fn ($target): string => (string) $target)
                ->all(),
        );

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '123.456',
        ])->assertOk();
        $this->assertNotSame([], (new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn());
    }

    public function test_web_logout_revokes_only_the_current_session_lease(): void
    {
        $user = User::factory()->create();
        $channel = $this->webChannelFor($user);
        $otherChannel = $this->createCanonicalWebLease($user);

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertNotNull(ChatRealtimeSession::query()
            ->where('user_id', $user->id)
            ->where('credential_hash', Str::afterLast($channel, '.web-'))
            ->value('revoked_at'));
        $this->assertNull(ChatRealtimeSession::query()
            ->where('user_id', $user->id)
            ->where('credential_hash', Str::afterLast($otherChannel, '.web-'))
            ->value('revoked_at'));
        $this->assertSame(
            ['private-'.$otherChannel],
            collect((new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn())
                ->map(fn ($target): string => (string) $target)
                ->all(),
        );
    }

    public function test_api_delivery_targets_only_active_unexpired_chat_tokens(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $active = $this->tokenFor($user, $client, ['chat:read']);
        $expired = $this->tokenFor($user, $client, ['chat:read'], now()->subSecond());
        $wrongScope = $this->tokenFor($user, $client, ['profile:read']);
        $revoked = $this->tokenFor($user, $client, ['chat:read']);
        $revoked->update(['revoked' => true]);

        $channel = $this->actAsApiToken($user, $client, $active);

        $this->getJson('/api/chat/realtime-channel')
            ->assertOk()
            ->assertJsonPath('data.channel', $channel);

        $targets = collect((new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn())
            ->map(fn ($target): string => (string) $target)
            ->all();

        $this->assertSame(['private-'.$channel], $targets);
        $this->assertNotNull($expired->fresh());
        $this->assertNotNull($wrongScope->fresh());
    }

    public function test_expired_api_token_stops_receiving_without_a_rotation_event(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $token = $this->tokenFor($user, $client, ['chat:read']);
        $this->actAsApiToken($user, $client, $token);
        $token->update(['expires_at' => now()->subSecond()]);

        $this->getJson('/api/chat/realtime-channel')->assertForbidden();
        $this->assertSame([], (new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn());
    }

    public function test_revoked_api_token_is_immediately_removed_from_publish_targets(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $token = $this->tokenFor($user, $client, ['chat:read']);
        $channel = $this->actAsApiToken($user, $client, $token);
        $otherToken = $this->tokenFor($user, $client, ['chat:read']);
        $otherChannel = $this->actAsApiToken($user, $client, $otherToken);
        UserSession::query()->create([
            'user_id' => $user->id,
            'oauth_access_token_id' => $token->id,
            'oauth_client_id' => $client->id,
        ]);

        app(ApiTokenService::class)->revokeToken($token, reason: 'test');

        $this->assertSame(
            ['private-'.$otherChannel],
            collect((new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn())
                ->map(fn ($target): string => (string) $target)
                ->all(),
        );
        $this->actAsApiToken($user, $client, $token, resolveChannel: false);
        $this->getJson('/api/chat/realtime-channel')->assertForbidden();
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '123.456',
        ])->assertForbidden();
        $this->assertDatabaseHas('user_sessions', [
            'oauth_access_token_id' => $token->id,
            'is_revoked' => true,
            'revoked_reason' => 'test',
        ]);
    }

    public function test_revoked_oauth_client_is_removed_from_publish_targets(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $token = $this->tokenFor($user, $client, ['chat:read']);
        $this->actAsApiToken($user, $client, $token);
        $client->update(['revoked' => true]);

        $this->getJson('/api/chat/realtime-channel')->assertForbidden();
        $this->assertSame([], (new ChatMessageSent(1, ['id' => 1], [$user->id]))->broadcastOn());
    }

    public function test_locked_user_is_removed_from_all_publish_targets(): void
    {
        Permission::findOrCreate(AppPermission::UsersLock->value, 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('ctv', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo(AppPermission::UsersLock->value);
        $target = User::factory()->create();
        $target->assignRole('ctv');
        $this->webChannelFor($target);

        $this->actingAs($admin)->postJson("/admin/users/{$target->id}/lock", [
            'type' => User::STATUS_LOCKED,
            'reason' => 'security test',
        ])->assertOk();

        $this->assertSame([], (new ChatMessageSent(1, ['id' => 1], [$target->id]))->broadcastOn());
    }

    private function webChannelFor(User $user): string
    {
        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->withCookie(
            (string) config('session.cookie'),
            $this->app['session']->driver()->getId(),
        )->withCredentials();

        $response = $this->getJson('/chat/realtime-channel')->assertOk();
        $this->persistCanonicalWebSession($user);

        return (string) $response->json('data.channel');
    }

    private function persistCanonicalWebSession(User $user): void
    {
        $lease = ChatRealtimeSession::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->firstOrFail();
        $sessionId = Crypt::decryptString($lease->session_locator);

        // The HTTP test harness does not flush its session store between requests.
        DB::table('sessions')->updateOrInsert(['id' => $sessionId], [
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(serialize([
                Auth::guard('web')->getName() => $user->id,
            ])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    private function createCanonicalWebLease(User $user): string
    {
        $sessionId = Str::random(40);
        $credentialHash = hash_hmac('sha256', "web:{$sessionId}", (string) config('app.key'));

        ChatRealtimeSession::query()->create([
            'user_id' => $user->id,
            'credential_hash' => $credentialHash,
            'session_locator' => Crypt::encryptString($sessionId),
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit secondary session',
            'payload' => base64_encode(serialize([
                Auth::guard('web')->getName() => $user->id,
            ])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return ChatRealtimeChannel::name($user->id, 'web-'.$credentialHash);
    }

    private function client(): Client
    {
        return app(ClientRepository::class)->createPasswordGrantClient(
            'Chat realtime test',
            'users',
        );
    }

    /** @param list<string> $scopes */
    private function tokenFor(
        User $user,
        Client $client,
        array $scopes,
        mixed $expiresAt = null,
    ): Token {
        return Passport::token()->newQuery()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => $scopes,
            'revoked' => false,
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);
    }

    private function actAsApiToken(
        User $user,
        Client $client,
        Token $token,
        bool $resolveChannel = true,
    ): string {
        Passport::actingAs($user, $token->scopes, 'api', $client);
        $user->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $token->id,
            'oauth_client_id' => $client->id,
            'oauth_user_id' => $user->id,
            'oauth_scopes' => $token->scopes,
        ]));

        if (! $resolveChannel) {
            return '';
        }

        $response = $this->getJson('/api/chat/realtime-channel')->assertOk();

        return (string) $response->json('data.channel');
    }
}
