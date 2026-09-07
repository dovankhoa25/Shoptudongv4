<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Events\ChatMessageSent;
use App\Events\UserEvent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatTip;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatTipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            AppPermission::ChatsView,
            AppPermission::ChatsViewAll,
            AppPermission::ChatsReply,
        ] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (['admin', 'ctv'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config()->set('chat.tips.min_amount', 1_000);
        config()->set('chat.tips.max_amount', 1_000_000);
        config()->set('chat.tips.daily_limit', 2_000_000);
    }

    public function test_customer_can_tip_the_assignee_atomically_and_receives_a_realtime_tip_message(): void
    {
        $customer = User::factory()->create(['balance' => 100_000]);
        $agent = $this->agent('ctv', 20_000);
        $agent->update(['chat_display_name' => 'Hỗ trợ viên Quỳnh']);
        $conversation = $this->conversationFor($customer, $agent, ChatConversation::STATUS_WAITING_CUSTOMER);
        Event::fake([ChatMessageSent::class, UserEvent::class]);

        $response = $this->actingAs($customer)->postJson(
            "/chat/conversations/{$conversation->id}/tips",
            [
                'recipient_id' => $agent->id,
                'amount' => 25_000,
                'idempotency_key' => (string) Str::uuid(),
                'note' => 'Cảm ơn bạn đã hỗ trợ.',
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.recipient.id', $agent->id)
            ->assertJsonPath('data.amount', 25_000)
            ->assertJsonPath('data.recipient_amount', 25_000)
            ->assertJsonPath('data.status', ChatTip::STATUS_COMPLETED)
            ->assertJsonPath('message.type', ChatMessage::TYPE_TIP)
            ->assertJsonPath('message.tip.recipient.username', $agent->username)
            ->assertJsonPath('message.tip.recipient.display_name', 'Hỗ trợ viên Quỳnh')
            ->assertJsonPath('message.tip.amount', 25_000)
            ->assertJsonPath('balances.payer.balance', 75_000)
            ->assertJsonPath('balances.payer.remaining_daily_limit', 1_975_000)
            ->assertJsonPath('conversation.status', ChatConversation::STATUS_WAITING_CUSTOMER)
            ->assertJsonPath('conversation.tipping.balance', 75_000)
            ->assertJsonPath('conversation.tipping.recipients.0.id', $agent->id)
            ->assertJsonPath('conversation.tipping.recipients.0.display_name', 'Hỗ trợ viên Quỳnh');

        $tipId = (int) $response->json('data.id');
        $messageId = (int) $response->json('message.id');
        $this->assertSame(75_000, (int) $customer->fresh()->balance);
        $this->assertSame(45_000, (int) $agent->fresh()->balance);
        $this->assertDatabaseHas('chat_tips', [
            'id' => $tipId,
            'message_id' => $messageId,
            'status' => ChatTip::STATUS_COMPLETED,
            'amount' => 25_000,
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $customer->id,
            'type' => Transaction::TYPE_CHAT_TIP_SENT,
            'amount' => -25_000,
            'related_type' => ChatTip::class,
            'related_id' => (string) $tipId,
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $agent->id,
            'type' => Transaction::TYPE_CHAT_TIP_RECEIVED,
            'amount' => 25_000,
            'related_type' => ChatTip::class,
            'related_id' => (string) $tipId,
        ]);
        $this->assertSame($messageId, (int) $conversation->fresh()->last_message_id);

        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event): bool => $event->message['type'] === ChatMessage::TYPE_TIP
            && $event->message['tip']['recipient']['id'] === $agent->id
            && ! array_key_exists('is_mine', $event->message));
        Event::assertDispatched(UserEvent::class, fn (UserEvent $event): bool => $event->userId === $customer->id
            && $event->type === 'update_balance'
            && $event->payload['balance'] === 75_000
            && $event->payload['remaining_daily_limit'] === 1_975_000);
        Event::assertDispatchedTimes(UserEvent::class, 2);
    }

    public function test_tip_idempotency_never_double_debits_and_rejects_reusing_the_key_for_other_data(): void
    {
        $customer = User::factory()->create(['balance' => 100_000]);
        $agent = $this->agent('ctv', 0);
        $conversation = $this->conversationFor($customer, $agent);
        $key = (string) Str::uuid();
        $payload = [
            'recipient_id' => $agent->id,
            'amount' => 10_000,
            'idempotency_key' => $key,
        ];

        $first = $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", $payload)
            ->assertCreated();

        // Mutable business rules must not hide a transaction that already
        // committed when the client retries after losing the first response.
        $conversation->update(['status' => ChatConversation::STATUS_CLOSED]);
        config()->set('chat.tips.min_amount', 50_000);
        config()->set('chat.tips.max_amount', 100_000);
        $agent->revokePermissionTo(AppPermission::ChatsReply->value);

        Event::fake([ChatMessageSent::class, UserEvent::class]);
        $retry = $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", $payload)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertSame($first->json('message.id'), $retry->json('message.id'));

        config()->set('chat.tips.min_amount', 1_000);
        config()->set('chat.tips.max_amount', 5_000);
        $secondRetry = $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", $payload)
            ->assertOk();
        $this->assertSame($first->json('data.id'), $secondRetry->json('data.id'));
        $this->assertSame($first->json('message.id'), $secondRetry->json('message.id'));

        $this->assertSame(90_000, (int) $customer->fresh()->balance);
        $this->assertSame(10_000, (int) $agent->fresh()->balance);
        $this->assertDatabaseCount('chat_tips', 1);
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertDatabaseCount('transactions', 2);
        Event::assertNotDispatched(ChatMessageSent::class);
        Event::assertNotDispatched(UserEvent::class);

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                ...$payload,
                'amount' => 11_000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_only_the_customer_can_tip_an_eligible_conversation_agent(): void
    {
        $customer = User::factory()->create(['balance' => 100_000]);
        $assignee = $this->agent('ctv', 0);
        $outsider = $this->agent('ctv', 0);
        $conversation = $this->conversationFor($customer, $assignee);

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                'recipient_id' => $outsider->id,
                'amount' => 5_000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_id');

        $this->actingAs($assignee)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                'recipient_id' => $outsider->id,
                'amount' => 5_000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                'recipient_id' => $customer->id,
                'amount' => 5_000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_id');

        $this->assertSame(100_000, (int) $customer->fresh()->balance);
        $this->assertDatabaseCount('chat_tips', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_public_agent_repliers_become_tip_recipients_but_internal_notes_do_not(): void
    {
        $customer = User::factory()->create(['balance' => 100_000]);
        $assignee = $this->agent('ctv', 0);
        $publicAgent = $this->agent('admin', 0, true);
        $internalOnlyAgent = $this->agent('admin', 0, true);
        $conversation = $this->conversationFor($customer, $assignee);

        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $publicAgent->id,
            'sender_kind' => ChatMessage::SENDER_AGENT,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'Mình đang hỗ trợ bạn.',
            'is_internal' => false,
        ]);
        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $internalOnlyAgent->id,
            'sender_kind' => ChatMessage::SENDER_AGENT,
            'type' => ChatMessage::TYPE_INTERNAL_NOTE,
            'body' => 'Ghi chú nội bộ.',
            'is_internal' => true,
        ]);

        $show = $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertOk();
        $recipientIds = collect($show->json('data.tipping.recipients'))->pluck('id')->all();
        $this->assertContains($assignee->id, $recipientIds);
        $this->assertContains($publicAgent->id, $recipientIds);
        $this->assertNotContains($internalOnlyAgent->id, $recipientIds);

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                'recipient_id' => $publicAgent->id,
                'amount' => 7_000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.recipient.id', $publicAgent->id);
    }

    public function test_balance_daily_limit_and_closed_chat_are_enforced_without_partial_writes(): void
    {
        config()->set('chat.tips.max_amount', 1_000);
        config()->set('chat.tips.daily_limit', 1_000);
        $customer = User::factory()->create(['balance' => 1_500]);
        $agent = $this->agent('ctv', 0);
        $conversation = $this->conversationFor($customer, $agent);

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                'recipient_id' => $agent->id,
                'amount' => 1_000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                'recipient_id' => $agent->id,
                'amount' => 1_000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $conversation->update(['status' => ChatConversation::STATUS_CLOSED]);
        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/tips", [
                'recipient_id' => $agent->id,
                'amount' => 1_000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversation');

        $this->assertSame(500, (int) $customer->fresh()->balance);
        $this->assertSame(1_000, (int) $agent->fresh()->balance);
        $this->assertDatabaseCount('chat_tips', 1);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_first_party_chat_api_can_tip_with_chat_read_and_write_scopes(): void
    {
        $customer = User::factory()->create(['balance' => 10_000]);
        $agent = $this->agent('ctv', 0);
        $conversation = $this->conversationFor($customer, $agent);
        Passport::actingAs($customer, ['chat:read', 'chat:write']);

        $this->postJson("/api/chat/conversations/{$conversation->id}/tips", [
            'recipient_id' => $agent->id,
            'amount' => 1_000,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.recipient.id', $agent->id);

        Passport::actingAs($customer, ['chat:read']);
        $this->postJson("/api/chat/conversations/{$conversation->id}/tips", [
            'recipient_id' => $agent->id,
            'amount' => 1_000,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    private function agent(string $role, int $balance, bool $viewAll = false): User
    {
        $agent = User::factory()->create([
            'balance' => $balance,
            'status' => User::STATUS_ACTIVE,
        ]);
        $agent->assignRole($role);
        $permissions = [AppPermission::ChatsView->value, AppPermission::ChatsReply->value];
        if ($viewAll) {
            $permissions[] = AppPermission::ChatsViewAll->value;
        }
        $agent->givePermissionTo($permissions);

        return $agent;
    }

    private function conversationFor(
        User $customer,
        User $assignee,
        string $status = ChatConversation::STATUS_WAITING_AGENT,
    ): ChatConversation {
        $conversation = ChatConversation::query()->create([
            'customer_id' => $customer->id,
            'category' => ChatConversation::CATEGORY_GENERAL,
            'assigned_to_id' => $assignee->id,
            'status' => $status,
            'priority' => ChatConversation::PRIORITY_NORMAL,
        ]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $customer->id,
            'role' => ChatParticipant::ROLE_CUSTOMER,
            'joined_at' => now(),
        ]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $assignee->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'joined_at' => now(),
        ]);

        return $conversation;
    }
}
