<?php

namespace Tests\Feature;

use App\Events\ChatInboxUpdated;
use App\Events\ChatMessageSent;
use App\Events\ChatReadUpdated;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Chat\ChatManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ChatAbuseProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ChatInboxUpdated::class, ChatMessageSent::class, ChatReadUpdated::class]);
    }

    public function test_health_probes_are_limited_without_sessions_or_database_queries(): void
    {
        DB::enableQueryLog();

        for ($i = 0; $i < 60; $i++) {
            $this->get('/up')->assertOk()->assertCookieMissing(config('session.cookie'));
        }
        $this->get('/up')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame([], DB::getQueryLog());

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->get('/up')->assertOk();
    }

    public function test_unauthenticated_chat_and_broadcast_bursts_are_rejected_before_database_authentication(): void
    {
        config(['chat.rate_limits.ingress_per_minute' => 2]);
        $this->getJson('/api/chat/unread-summary')->assertUnauthorized();
        $this->postJson('/api/broadcasting/auth')->assertUnauthorized();

        DB::enableQueryLog();
        $this->withHeaders(['X-Forwarded-For' => '192.0.2.9'])
            ->getJson('/api/chat/conversations')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame([], DB::getQueryLog());

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->getJson('/api/chat/unread-summary')->assertUnauthorized();
    }

    public function test_ingress_rejection_preserves_cors_for_a_storefront(): void
    {
        config(['chat.rate_limits.ingress_per_minute' => 1]);
        $origin = 'https://store.example.test';
        config(['cors.static_allowed_origins' => [$origin]]);
        $this->withHeaders(['Origin' => $origin])->getJson('/api/chat/unread-summary')->assertUnauthorized();
        $this->getJson('/api/chat/unread-summary')->assertStatus(429)
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Retry-After');
    }

    public function test_preflight_does_not_consume_chat_ingress_budget(): void
    {
        config(['chat.rate_limits.ingress_per_minute' => 1]);
        $origin = 'https://store.example.test';
        config(['cors.static_allowed_origins' => [$origin]]);
        $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/chat/unread-summary')->assertNoContent();
        $this->getJson('/api/chat/unread-summary')->assertUnauthorized();
    }

    public function test_chat_budget_combines_reads_and_writes_and_is_isolated_between_users(): void
    {
        $customer = User::factory()->create();
        [$conversation] = app(ChatManager::class)->resolve($customer, []);
        Passport::actingAs($customer, ['chat:read', 'chat:write', 'profile:read']);

        $url = '/api/chat/conversations/'.$conversation->id.'/messages';
        $this->postJson($url, ['body' => 'Một'])->assertCreated();
        for ($i = 0; $i < 99; $i++) {
            $this->getJson('/api/chat/unread-summary')->assertOk();
        }
        $this->postJson($url, ['body' => 'Vượt tổng hạn mức'])->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/chat/unread-summary')->assertStatus(429);
        $this->getJson('/api/me/balance')->assertOk();
        $this->assertSame(1, ChatMessage::query()->where('sender_id', $customer->id)->count());

        Passport::actingAs(User::factory()->create(), ['chat:read']);
        $this->getJson('/api/chat/unread-summary')->assertOk();

        $this->travel(61)->seconds();
        Passport::actingAs($customer, ['chat:read', 'chat:write']);
        $this->getJson('/api/chat/unread-summary')->assertOk();
        $this->postJson($url, ['body' => 'Sau khi hết giới hạn'])->assertCreated();
    }

    public function test_send_limit_is_shared_between_api_and_web_and_isolated_between_users(): void
    {
        config(['chat.rate_limits.send_per_minute' => 1]);
        $customer = User::factory()->create();
        [$conversation] = app(ChatManager::class)->resolve($customer, []);
        Passport::actingAs($customer, ['chat:read', 'chat:write']);
        $this->postJson('/api/chat/conversations/'.$conversation->id.'/messages', ['body' => 'API'])->assertCreated();
        $this->getJson('/api/chat/unread-summary')->assertOk();
        $this->getJson('/api/chat/unread-summary')->assertOk();
        $this->actingAs($customer, 'web')
            ->postJson('/chat/conversations/'.$conversation->id.'/messages', ['body' => 'Web'])->assertStatus(429);

        $other = User::factory()->create();
        [$otherConversation] = app(ChatManager::class)->resolve($other, []);
        $this->actingAs($other, 'web')
            ->postJson('/chat/conversations/'.$otherConversation->id.'/messages', ['body' => 'Khách khác'])->assertCreated();
    }

    public function test_resolve_burst_does_not_create_extra_chats_or_broadcast_repeated_updates(): void
    {
        config(['chat.rate_limits.resolve_per_minute' => 2]);
        $customer = User::factory()->create();
        Passport::actingAs($customer, ['chat:read', 'chat:write']);
        $this->postJson('/api/chat/conversations/resolve')->assertCreated();
        $joinedAt = ChatParticipant::query()->sole()->joined_at;
        $this->travel(2)->seconds();
        DB::enableQueryLog();
        $this->postJson('/api/chat/conversations/resolve')->assertOk();
        $participantUpdates = collect(DB::getQueryLog())->filter(fn (array $query): bool =>
            str_starts_with(strtolower($query['query']), 'update') && str_contains($query['query'], 'chat_participants'));
        $this->assertCount(0, $participantUpdates);
        $this->postJson('/api/chat/conversations/resolve')->assertStatus(429);
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertTrue($joinedAt->equalTo(ChatParticipant::query()->sole()->joined_at));
        Event::assertDispatchedTimes(ChatInboxUpdated::class, 1);

        // Exhausting resolve must not prevent the customer from sending a message.
        $id = ChatConversation::query()->sole()->id;
        $this->postJson('/api/chat/conversations/'.$id.'/messages', ['body' => 'Cần hỗ trợ'])->assertCreated();
    }

    public function test_default_resolve_limit_allows_one_request_per_user_per_minute(): void
    {
        $this->assertSame(1, config('chat.rate_limits.resolve_per_minute'));
        $customer = User::factory()->create();
        Passport::actingAs($customer, ['chat:read', 'chat:write']);
        $id = $this->postJson('/api/chat/conversations/resolve')->assertCreated()->json('data.id');
        $this->postJson('/api/chat/conversations/resolve')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/chat/conversations/'.$id)->assertOk();
        $this->postJson('/api/chat/conversations/'.$id.'/messages', ['body' => 'Vẫn nhắn được'])->assertCreated();

        $other = User::factory()->create();
        Passport::actingAs($other, ['chat:read', 'chat:write']);
        $this->postJson('/api/chat/conversations/resolve')->assertCreated();

        $this->travel(61)->seconds();
        Passport::actingAs($customer, ['chat:read', 'chat:write']);
        $this->postJson('/api/chat/conversations/resolve')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('chat_conversations', 2);
    }

    public function test_uploads_have_a_separate_limit_and_text_remains_available(): void
    {
        config(['chat.rate_limits.upload_per_minute' => 1]);
        Storage::fake('chat');
        $customer = User::factory()->create();
        [$conversation] = app(ChatManager::class)->resolve($customer, []);
        $url = '/chat/conversations/'.$conversation->id.'/messages';
        $this->actingAs($customer)->post($url, [
            'client_message_id' => (string) Str::uuid(),
            'images' => [UploadedFile::fake()->image('first.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->post($url, [
            'client_message_id' => (string) Str::uuid(),
            'images' => [UploadedFile::fake()->image('second.jpg')],
        ], ['Accept' => 'application/json'])->assertStatus(429);
        $this->postJson($url, ['body' => 'Vẫn gửi được chữ'])->assertCreated();
        $this->assertDatabaseCount('media', 1);
    }
}
