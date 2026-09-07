<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Events\ChatInboxUpdated;
use App\Events\ChatMessageSent;
use App\Events\ChatReadUpdated;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\ChatRealtimeSession;
use App\Models\User;
use App\Services\Chat\ChatRealtimeNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatBroadcastAuthorizationTest extends TestCase
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

        foreach ([AppPermission::ChatsView, AppPermission::ChatsReply] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('ctv', 'web');
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

    public function test_each_user_can_only_authorize_their_own_chat_delivery_channel(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = User::factory()->create();
        $agent->assignRole('ctv');
        $agent->givePermissionTo([AppPermission::ChatsView->value, AppPermission::ChatsReply->value]);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('admin');
        $admin->givePermissionTo(AppPermission::ChatsView->value);
        $conversation = ChatConversation::query()->create([
            'customer_id' => $owner->id,
            'assigned_to_id' => $agent->id,
            'category' => 'general',
            'status' => 'waiting_agent',
            'priority' => 'normal',
        ]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $agent->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'joined_at' => now(),
        ]);

        $this->assertTrue($admin->canViewAllAdminData());
        $this->assertTrue($admin->can(AppPermission::ChatsView->value));
        $this->assertTrue($admin->can('view', $conversation));
        $this->authorizeChannel($admin, 'private-Admin.chat')->assertForbidden();
        $this->authorizeChannel($agent, 'private-Admin.chat')->assertForbidden();
        $this->authorizeChannel($owner, 'private-Admin.chat')->assertForbidden();

        $ownerChannel = $this->webChannel($owner);
        $this->authorizeChannel($owner, 'private-'.$ownerChannel)->assertOk();
        $this->authorizeChannel($agent, 'private-'.$ownerChannel)->assertForbidden();
        $this->authorizeChannel($stranger, 'private-'.$ownerChannel)->assertForbidden();
        $this->authorizeChannel($owner, "private-Chat.User.{$owner->id}.web-invalid")->assertForbidden();
        $this->authorizeChannel($owner, "private-Chat.User.{$owner->id}")->assertForbidden();
        $this->authorizeChannel($admin, 'private-Chat.Inbox.Admin')->assertForbidden();
        $this->authorizeChannel($admin, "private-Chat.Conversation.{$conversation->id}")->assertForbidden();
    }

    public function test_api_chat_channel_requires_chat_read_scope(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $chatToken = $this->tokenFor($user, $client, ['chat:read']);
        $channel = $this->actAsApiToken($user, $client, $chatToken);
        $profileToken = $this->tokenFor($user, $client, ['profile:read']);

        $this->actAsApiToken($user, $client, $profileToken, resolveChannel: false);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '123.456',
        ])->assertForbidden();

        $this->actAsApiToken($user, $client, $chatToken, resolveChannel: false);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '123.456',
        ])->assertOk();
    }

    public function test_locked_user_cannot_authorize_any_chat_realtime_channel(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('admin');
        $admin->givePermissionTo([AppPermission::ChatsView->value, AppPermission::ChatsReply->value]);
        $channel = $this->webChannel($admin);
        $this->authorizeChannel($admin, 'private-'.$channel)->assertOk();
        $admin->update(['status' => User::STATUS_LOCKED]);

        $this->authorizeChannel($admin, 'private-'.$channel)->assertForbidden();
        $this->authorizeChannel($admin, 'private-Chat.Inbox.Admin')->assertForbidden();
        $this->authorizeChannel($admin, 'private-Admin.chat')->assertForbidden();
    }

    public function test_chat_events_never_target_a_shared_admin_chat_channel(): void
    {
        $events = [
            new ChatMessageSent(1, ['id' => 1], []),
            new ChatInboxUpdated('message', ['id' => 1], []),
            new ChatReadUpdated(1, ['id' => 1], 1, now()->toIso8601String()),
        ];

        foreach ($events as $event) {
            $channels = collect($event->broadcastOn())
                ->map(fn ($channel): string => (string) $channel)
                ->all();

            $this->assertNotContains('private-Admin.chat', $channels);
        }
    }

    public function test_events_target_only_users_who_are_authorized_at_publish_time(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('admin');
        $owner->givePermissionTo(AppPermission::ChatsView->value);
        $agent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $agent->assignRole('ctv');
        $agent->givePermissionTo([AppPermission::ChatsView->value, AppPermission::ChatsReply->value]);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('admin');
        $admin->givePermissionTo(AppPermission::ChatsView->value);
        $revokedAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $revokedAdmin->assignRole('admin');
        $revokedAdmin->givePermissionTo(AppPermission::ChatsView->value);
        $lockedAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $lockedAdmin->assignRole('admin');
        $lockedAdmin->givePermissionTo(AppPermission::ChatsView->value);

        $revokedAdmin->revokePermissionTo(AppPermission::ChatsView->value);
        $lockedAdmin->update(['status' => User::STATUS_LOCKED]);

        $conversation = ChatConversation::query()->create([
            'customer_id' => $owner->id,
            'assigned_to_id' => $agent->id,
            'category' => 'general',
            'status' => 'waiting_agent',
            'priority' => 'normal',
        ]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $agent->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'joined_at' => now(),
        ]);

        Event::fake([ChatMessageSent::class]);
        $notifier = app(ChatRealtimeNotifier::class);
        $notifier->message($conversation, ['id' => 1], false);
        $notifier->message($conversation, ['id' => 2], true);

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($owner, $agent, $admin, $revokedAdmin, $lockedAdmin): bool {
            if (($event->message['id'] ?? null) !== 1) {
                return false;
            }

            $recipients = collect($event->recipientIds)->sort()->values()->all();
            $expected = collect([$owner->id, $agent->id, $admin->id])->sort()->values()->all();

            return $recipients === $expected
                && ! in_array($revokedAdmin->id, $recipients, true)
                && ! in_array($lockedAdmin->id, $recipients, true)
                && method_exists($event, 'dontBroadcastToCurrentUser');
        });
        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($agent, $admin): bool {
            $recipients = collect($event->recipientIds)->sort()->values()->all();
            $expected = collect([$agent->id, $admin->id])->sort()->values()->all();

            return ($event->message['id'] ?? null) === 2
                && $recipients === $expected;
        });
    }

    private function authorizeChannel(User $user, string $channel)
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '123.456',
        ]);
    }

    private function webChannel(User $user): string
    {
        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertRedirect();

        $this->withCookie(
            (string) config('session.cookie'),
            $this->app['session']->driver()->getId(),
        )->withCredentials();

        $response = $this->getJson('/chat/realtime-channel')->assertOk();

        $lease = ChatRealtimeSession::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->firstOrFail();
        $sessionId = Crypt::decryptString($lease->session_locator);

        DB::table('sessions')->updateOrInsert(['id' => $sessionId], [
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(serialize([
                Auth::guard('web')->getName() => $user->id,
            ])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return (string) $response->json('data.channel');
    }

    private function client(): Client
    {
        return app(ClientRepository::class)->createPasswordGrantClient(
            'Broadcast auth test',
            'users',
        );
    }

    /** @param list<string> $scopes */
    private function tokenFor(User $user, Client $client, array $scopes): Token
    {
        return Passport::token()->newQuery()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => $scopes,
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function actAsApiToken(
        User $user,
        Client $client,
        Token $token,
        bool $resolveChannel = true,
    ): ?string {
        Passport::actingAs($user, $token->scopes, 'api', $client);
        $user->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $token->id,
            'oauth_client_id' => $client->id,
            'oauth_user_id' => $user->id,
            'oauth_scopes' => $token->scopes,
        ]));

        if (! $resolveChannel) {
            return null;
        }

        return (string) $this->getJson('/api/chat/realtime-channel')
            ->assertOk()
            ->json('data.channel');
    }
}
