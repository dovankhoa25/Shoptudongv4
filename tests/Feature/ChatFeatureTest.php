<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Events\ChatInboxUpdated;
use App\Events\ChatMessageSent;
use App\Events\ChatReadUpdated;
use App\Models\Category;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\GoldTransaction;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Chat\ChatManager;
use App\Services\Chat\ChatRealtimeNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            AppPermission::ChatsView,
            AppPermission::ChatsViewAll,
            AppPermission::ChatsReply,
            AppPermission::ChatsAssign,
            AppPermission::ChatsManage,
            AppPermission::ServiceOrdersProcess,
        ] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (['admin', 'super-admin', 'ctv'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_customer_can_resolve_send_and_read_a_general_conversation(): void
    {
        $customer = User::factory()->create();

        $conversationResponse = $this->actingAs($customer)->postJson('/chat/conversations/resolve', [
            'category' => 'general',
            'source_app' => 'web-game',
            'source_url' => 'https://example.test/dashboard',
        ]);

        $conversationResponse
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.status', ChatConversation::STATUS_WAITING_AGENT);

        $conversationId = (int) $conversationResponse->json('data.id');
        $clientMessageId = (string) Str::uuid();
        $messageResponse = $this->actingAs($customer)->postJson("/chat/conversations/{$conversationId}/messages", [
            'body' => 'Mình cần hỗ trợ một đơn hàng.',
            'client_message_id' => $clientMessageId,
        ]);

        $messageResponse
            ->assertCreated()
            ->assertJsonPath('data.sender_kind', ChatMessage::SENDER_CUSTOMER)
            ->assertJsonPath('data.body', 'Mình cần hỗ trợ một đơn hàng.');

        $messageId = (int) $messageResponse->json('data.id');

        $this->actingAs($customer)
            ->patchJson("/chat/conversations/{$conversationId}/read", ['last_read_message_id' => $messageId])
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', $messageId);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $customer->id,
            'role' => ChatParticipant::ROLE_CUSTOMER,
            'last_read_message_id' => $messageId,
        ]);
    }

    public function test_new_customer_conversation_gets_one_non_actionable_system_welcome(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [AppPermission::ChatsView]);
        $payload = [
            'category' => ChatConversation::CATEGORY_GENERAL,
            'source_app' => 'web-game',
        ];

        $firstResponse = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', ChatConversation::STATUS_WAITING_AGENT)
            ->assertJsonPath('data.last_message', null)
            ->assertJsonPath('data.latest_message_id', null)
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('welcome_message.sender_kind', ChatMessage::SENDER_SYSTEM)
            ->assertJsonPath('welcome_message.type', ChatMessage::TYPE_SYSTEM)
            ->assertJsonPath('welcome_message.body', 'Hỗ trợ viên đã sẵn sàng. Bạn cần hỗ trợ gì không?')
            ->assertJsonPath('welcome_message.metadata.event', ChatMessage::EVENT_WELCOME_MESSAGE);
        $conversationId = (int) $firstResponse->json('data.id');
        $welcomeMessageId = (int) $firstResponse->json('welcome_message.id');

        $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId)
            ->assertJsonPath('welcome_message', null);

        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertDatabaseHas('chat_messages', [
            'id' => $welcomeMessageId,
            'conversation_id' => $conversationId,
            'sender_id' => null,
            'sender_kind' => ChatMessage::SENDER_SYSTEM,
            'type' => ChatMessage::TYPE_SYSTEM,
            'is_internal' => false,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $customer->id,
            'last_read_message_id' => $welcomeMessageId,
        ]);
        $this->assertNull(ChatConversation::query()->findOrFail($conversationId)->last_message_id);

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $welcomeMessageId);

        foreach ([$customer, $admin] as $viewer) {
            $baseUrl = $viewer->is($customer) ? '/chat' : '/admin/chat';
            $this->actingAs($viewer)
                ->getJson("{$baseUrl}/conversations")
                ->assertOk()
                ->assertJsonPath('unread_total', 0)
                ->assertJsonPath('data.0.unread_count', 0)
                ->assertJsonPath('data.0.last_message', null)
                ->assertJsonPath('data.0.latest_message_id', null);
        }
    }

    public function test_resolve_reuses_the_same_service_order_chat_and_rejects_another_users_order(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $service = Service::query()->create([
            'name' => 'Cày thuê nhiệm vụ',
            'default_price' => 50000,
            'status' => true,
        ]);
        $ownOrder = $this->serviceOrder($customer, $service);
        $foreignOrder = $this->serviceOrder($otherCustomer, $service);

        $payload = ['subject_type' => 'service_order', 'subject_id' => $ownOrder->id];
        $firstId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', $payload)
            ->assertCreated()
            ->assertJsonPath('data.subject.label', 'Đơn dịch vụ #'.$ownOrder->id)
            ->json('data.id');
        $secondId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', $payload)
            ->assertOk()
            ->json('data.id');

        $this->assertSame($firstId, $secondId);
        $this->assertDatabaseCount('chat_conversations', 1);

        $this->actingAs($customer)->postJson('/chat/conversations/resolve', [
            'subject_type' => 'service_order',
            'subject_id' => $foreignOrder->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_first_order_is_attached_to_general_chat_but_a_different_order_gets_its_own_chat(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $receiver = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $service = Service::query()->create(['name' => 'Săn đệ tử', 'status' => true]);
        $firstOrder = $this->serviceOrder($customer, $service, $receiver);
        $secondOrder = $this->serviceOrder($customer, $service, $receiver);

        $generalId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', ['category' => 'general'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$generalId}/messages", ['body' => 'Admin tư vấn giúp mình.'])
            ->assertCreated();
        $this->actingAs($admin)
            ->postJson("/admin/chat/conversations/{$generalId}/messages", ['body' => 'Bạn chọn đơn cần hỗ trợ nhé.'])
            ->assertCreated();

        $attachedId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $firstOrder->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $receiver->id)
            ->assertJsonPath('data.status', ChatConversation::STATUS_WAITING_AGENT)
            ->json('data.id');

        $this->assertSame($generalId, $attachedId);
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $generalId,
            'subject_type' => 'service_order',
            'subject_id' => $firstOrder->id,
            'assigned_to_id' => $receiver->id,
        ]);
        $this->assertNotNull(ChatParticipant::query()
            ->where('conversation_id', $generalId)
            ->where('user_id', $admin->id)
            ->value('left_at'));

        $secondId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $secondOrder->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotSame($attachedId, $secondId);
        $this->assertDatabaseCount('chat_conversations', 2);
    }

    public function test_accepting_a_pending_service_order_assigns_its_unclaimed_chat_to_the_receiver(): void
    {
        $customer = User::factory()->create();
        $receiver = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ServiceOrdersProcess,
        ]);
        $otherCtv = $this->agent('ctv', [AppPermission::ServiceOrdersProcess]);
        $service = Service::query()->create(['name' => 'Làm nhiệm vụ thuê', 'status' => true]);
        $category = Category::query()->create(['game_type_id' => 1, 'name' => 'Dịch vụ kiểm thử']);
        $service->categories()->attach($category);
        $receiver->categories()->attach($category, ['can_post' => true]);
        $otherCtv->categories()->attach($category, ['can_post' => true]);
        $order = $this->serviceOrder($customer, $service, status: 'pending');

        $conversationId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assignee', null)
            ->json('data.id');

        $this->actingAs($receiver)
            ->post("/admin/services/orders/{$order->id}/accept")
            ->assertRedirect()
            ->assertSessionHas('message', 'Nhận đơn thành công!');

        $this->assertDatabaseHas('service_orders', [
            'id' => $order->id,
            'receiver_id' => $receiver->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'assigned_to_id' => $receiver->id,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $receiver->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'left_at' => null,
        ]);

        $this->actingAs($otherCtv)
            ->post("/admin/services/orders/{$order->id}/accept")
            ->assertRedirect()
            ->assertSessionHas('error', 'Đã có người nhận đơn này rồi.');

        $this->assertSame($receiver->id, $order->refresh()->receiver_id);
        $this->assertSame($receiver->id, ChatConversation::query()->findOrFail($conversationId)->assigned_to_id);
    }

    public function test_unassigned_order_chat_still_notifies_the_ctv_who_owns_the_order(): void
    {
        $customer = User::factory()->create();
        $receiver = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $service = Service::query()->create(['name' => 'Dịch vụ có CTV', 'status' => true]);
        $order = $this->serviceOrder($customer, $service, $receiver);
        $conversation = ChatConversation::query()->create([
            'customer_id' => $customer->id,
            'category' => ChatConversation::CATEGORY_ORDER_SUPPORT,
            'subject_type' => 'service_order',
            'subject_id' => $order->id,
            'assigned_to_id' => null,
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'priority' => ChatConversation::PRIORITY_NORMAL,
        ]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $receiver->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'joined_at' => now()->subMinute(),
            'left_at' => now(),
        ]);

        Event::fake([ChatMessageSent::class]);

        app(ChatRealtimeNotifier::class)->message($conversation, ['id' => 123], false);

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($customer, $receiver): bool {
            $recipients = collect($event->recipientIds)->sort()->values()->all();
            $expected = collect([$customer->id, $receiver->id])->sort()->values()->all();

            return $recipients === $expected;
        });
    }

    public function test_direct_view_all_permission_receives_realtime_for_unrelated_chat(): void
    {
        $customer = User::factory()->create();
        $globalCtv = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsViewAll,
        ]);
        $scopedCtv = $this->agent('ctv', [AppPermission::ChatsView]);
        $conversation = $this->conversationFor($customer);

        Event::fake([ChatMessageSent::class]);

        app(ChatRealtimeNotifier::class)->message($conversation, ['id' => 124], false);

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($customer, $globalCtv, $scopedCtv): bool {
            $recipients = collect($event->recipientIds);

            return $recipients->contains($customer->id)
                && $recipients->contains($globalCtv->id)
                && ! $recipients->contains($scopedCtv->id);
        });
    }

    public function test_ctv_accepting_pending_order_takes_over_attached_general_chat_from_admin(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $receiver = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ServiceOrdersProcess,
        ]);
        $service = Service::query()->create(['name' => 'Up sức mạnh', 'status' => true]);
        $category = Category::query()->create(['game_type_id' => 1, 'name' => 'Dịch vụ bàn giao chat']);
        $service->categories()->attach($category);
        $receiver->categories()->attach($category, ['can_post' => true]);
        $order = $this->serviceOrder($customer, $service, status: 'pending');

        $conversationId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', ['category' => 'general'])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversationId}/messages", ['body' => 'Mình muốn hỏi dịch vụ này.'])
            ->assertCreated();
        $this->actingAs($admin)
            ->postJson("/admin/chat/conversations/{$conversationId}/messages", ['body' => 'Bạn chọn đúng đơn để CTV hỗ trợ nhé.'])
            ->assertCreated();

        $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId)
            ->assertJsonPath('data.assignee.id', $admin->id);

        $this->actingAs($receiver)
            ->post("/admin/services/orders/{$order->id}/accept")
            ->assertRedirect()
            ->assertSessionHas('message', 'Nhận đơn thành công!');

        $conversation = ChatConversation::query()->findOrFail($conversationId);
        $this->assertSame($receiver->id, $conversation->assigned_to_id);
        $this->assertSame(3, $conversation->messages()->count());
        $this->assertNotNull(ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $admin->id)
            ->value('left_at'));
        $this->actingAs($receiver)
            ->getJson("/admin/chat/conversations/{$conversationId}")
            ->assertOk();
    }

    public function test_client_message_id_makes_sending_idempotent(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $clientMessageId = (string) Str::uuid();
        $payload = ['body' => 'Không gửi trùng tin này', 'client_message_id' => $clientMessageId];

        $firstId = $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/messages", $payload)
            ->assertCreated()
            ->json('data.id');
        $secondId = $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/messages", $payload)
            ->assertOk()
            ->json('data.id');

        $this->assertSame($firstId, $secondId);
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_older_messages_can_be_loaded_without_truncating_chat_history(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);

        foreach (range(1, 65) as $number) {
            $conversation->messages()->create([
                'sender_id' => $customer->id,
                'sender_kind' => ChatMessage::SENDER_CUSTOMER,
                'type' => ChatMessage::TYPE_TEXT,
                'body' => "Tin nhắn {$number}",
            ]);
        }

        $firstPage = $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(60, 'messages')
            ->assertJsonPath('has_more', true);

        $oldestLoadedId = $firstPage->json('messages.0.id');

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}/messages?before={$oldestLoadedId}&limit=60")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.0.body', 'Tin nhắn 1');
    }

    public function test_messages_after_id_returns_an_ascending_bounded_recovery_delta(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $messages = collect(range(1, 5))->map(fn (int $number) => $conversation->messages()->create([
            'sender_id' => $customer->id,
            'sender_kind' => ChatMessage::SENDER_CUSTOMER,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => "Tin phục hồi {$number}",
        ]));

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}/messages?after_id=0&limit=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $messages[0]->id)
            ->assertJsonPath('data.1.id', $messages[1]->id)
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('next_after', $messages[1]->id);

        $firstDelta = $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}/messages?after_id={$messages[1]->id}&limit=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $messages[2]->id)
            ->assertJsonPath('data.1.id', $messages[3]->id)
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('next_before', null)
            ->assertJsonPath('next_after', $messages[3]->id);

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}/messages?after_id={$firstDelta->json('next_after')}&limit=2")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $messages[4]->id)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_after', null);

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}/messages?before={$messages[4]->id}&after_id={$messages[1]->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('after_id');
    }

    public function test_conversation_index_returns_unread_total_beyond_the_first_page(): void
    {
        $customer = User::factory()->create();

        foreach (range(1, 55) as $number) {
            $conversation = $this->conversationFor($customer);
            $conversation->messages()->create([
                'sender_id' => null,
                'sender_kind' => ChatMessage::SENDER_SYSTEM,
                'type' => ChatMessage::TYPE_SYSTEM,
                'body' => 'Thông báo '.$number,
                'is_internal' => false,
            ]);
        }

        $this->actingAs($customer)
            ->getJson('/chat/conversations?per_page=50')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('unread_total', 55);
    }

    public function test_admin_inbox_separates_active_and_completed_conversations_with_scoped_counts(): void
    {
        $admin = $this->agent('admin', [AppPermission::ChatsView]);
        $conversations = [];

        foreach ([
            ChatConversation::STATUS_WAITING_AGENT,
            ChatConversation::STATUS_WAITING_CUSTOMER,
            ChatConversation::STATUS_RESOLVED,
            ChatConversation::STATUS_CLOSED,
        ] as $status) {
            $customer = User::factory()->create();
            $conversation = $this->conversationFor($customer);
            $conversation->update([
                'status' => $status,
                'assigned_to_id' => in_array($status, [
                    ChatConversation::STATUS_WAITING_AGENT,
                    ChatConversation::STATUS_RESOLVED,
                ], true) ? $admin->id : null,
                'resolved_at' => in_array($status, [
                    ChatConversation::STATUS_RESOLVED,
                    ChatConversation::STATUS_CLOSED,
                ], true) ? now() : null,
                'last_message_at' => now(),
            ]);
            $conversation->messages()->create([
                'sender_id' => $customer->id,
                'sender_kind' => ChatMessage::SENDER_CUSTOMER,
                'type' => ChatMessage::TYPE_TEXT,
                'body' => 'Tin chưa đọc '.$status,
                'is_internal' => false,
            ]);
            $conversations[$status] = $conversation;
        }

        $activeResponse = $this->actingAs($admin)
            ->getJson('/admin/chat/conversations')
            ->assertOk()
            ->assertJsonPath('view', 'active')
            ->assertJsonPath('period', 'all')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.all', 4)
            ->assertJsonPath('counts.active', 2)
            ->assertJsonPath('counts.completed', 2)
            ->assertJsonPath('counts.waiting_agent', 1)
            ->assertJsonPath('counts.waiting_customer', 1)
            ->assertJsonPath('counts.resolved', 1)
            ->assertJsonPath('counts.closed', 1)
            ->assertJsonPath('unread_counts.all', 4)
            ->assertJsonPath('unread_counts.active', 2)
            ->assertJsonPath('unread_counts.completed', 2)
            ->assertJsonPath('unread_total', 2);

        $this->assertEqualsCanonicalizing(
            [
                $conversations[ChatConversation::STATUS_WAITING_AGENT]->id,
                $conversations[ChatConversation::STATUS_WAITING_CUSTOMER]->id,
            ],
            collect($activeResponse->json('data'))->pluck('id')->all(),
        );

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations?view=completed&status=closed')
            ->assertOk()
            ->assertJsonPath('view', 'completed')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversations[ChatConversation::STATUS_CLOSED]->id)
            ->assertJsonPath('counts.active', 2)
            ->assertJsonPath('counts.completed', 2)
            ->assertJsonPath('unread_total', 1);

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations?view=all&assignment=mine')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.all', 2)
            ->assertJsonPath('counts.active', 1)
            ->assertJsonPath('counts.completed', 1)
            ->assertJsonPath('unread_counts.active', 1)
            ->assertJsonPath('unread_counts.completed', 1);
    }

    public function test_active_admin_inbox_keeps_status_groups_together_then_prioritizes_unread(): void
    {
        $admin = $this->agent('admin', [AppPermission::ChatsView]);

        $readConversation = $this->conversationFor(User::factory()->create());
        $readConversation->update([
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'last_message_at' => now(),
        ]);

        $waitingCustomer = $this->conversationFor($waitingCustomerUser = User::factory()->create());
        $waitingCustomer->update([
            'status' => ChatConversation::STATUS_WAITING_CUSTOMER,
            'last_message_at' => now()->subMinute(),
        ]);
        $waitingCustomer->messages()->create([
            'sender_id' => $waitingCustomerUser->id,
            'sender_kind' => ChatMessage::SENDER_CUSTOMER,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'Tin chờ khách mới hơn',
            'is_internal' => false,
        ]);

        $waitingAgent = $this->conversationFor($waitingAgentUser = User::factory()->create());
        $waitingAgent->update([
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'last_message_at' => now()->subDay(),
        ]);
        $waitingAgent->messages()->create([
            'sender_id' => $waitingAgentUser->id,
            'sender_kind' => ChatMessage::SENDER_CUSTOMER,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'Tin chờ admin cũ hơn',
            'is_internal' => false,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations?view=active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $waitingAgent->id)
            ->assertJsonPath('data.1.id', $readConversation->id)
            ->assertJsonPath('data.2.id', $waitingCustomer->id);
    }

    public function test_completed_period_filter_uses_resolution_time_without_narrowing_counts(): void
    {
        $admin = $this->agent('admin', [AppPermission::ChatsView]);
        $recent = $this->conversationFor(User::factory()->create());
        $recent->update([
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_at' => now()->subDays(2),
        ]);
        $old = $this->conversationFor(User::factory()->create());
        $old->update([
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_at' => now()->subDays(10),
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations?view=completed&period=7d')
            ->assertOk()
            ->assertJsonPath('period', '7d')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id)
            ->assertJsonPath('counts.completed', 2);

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations?view=completed&period=365d')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    public function test_inbox_counts_respect_assignment_and_search_scope(): void
    {
        $admin = $this->agent('admin', [AppPermission::ChatsView]);
        $otherAdmin = $this->agent('admin', [AppPermission::ChatsView]);
        $matchingCustomer = User::factory()->create(['username' => 'archive-alpha-target']);
        $otherCustomer = User::factory()->create(['username' => 'archive-beta-target']);
        $matchingOtherAssignment = User::factory()->create(['username' => 'archive-alpha-other']);

        $matching = $this->conversationFor($matchingCustomer);
        $matching->update([
            'assigned_to_id' => $admin->id,
            'status' => ChatConversation::STATUS_WAITING_AGENT,
        ]);

        $this->conversationFor($otherCustomer)->update([
            'assigned_to_id' => $admin->id,
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
        $this->conversationFor($matchingOtherAssignment)->update([
            'assigned_to_id' => $otherAdmin->id,
            'status' => ChatConversation::STATUS_CLOSED,
            'resolved_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations?view=all&assignment=mine&search=archive-alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('counts.all', 1)
            ->assertJsonPath('counts.active', 1)
            ->assertJsonPath('counts.completed', 0)
            ->assertJsonPath('counts.waiting_agent', 1)
            ->assertJsonPath('counts.waiting_customer', 0)
            ->assertJsonPath('counts.resolved', 0)
            ->assertJsonPath('counts.closed', 0);
    }

    public function test_completed_inbox_orders_by_resolved_at_before_last_message_at(): void
    {
        $admin = $this->agent('admin', [AppPermission::ChatsView]);
        $recentlyResolved = $this->conversationFor(User::factory()->create());
        $recentlyResolved->update([
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_at' => now()->subDay(),
            'last_message_at' => now()->subDays(10),
        ]);
        $olderResolutionWithNewerMessage = $this->conversationFor(User::factory()->create());
        $olderResolutionWithNewerMessage->update([
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_at' => now()->subDays(5),
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations?view=completed')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $recentlyResolved->id)
            ->assertJsonPath('data.1.id', $olderResolutionWithNewerMessage->id);
    }

    public function test_customer_conversation_index_still_defaults_to_all_statuses(): void
    {
        $customer = User::factory()->create();
        $active = $this->conversationFor($customer);
        $closed = $this->conversationFor($customer);
        $closed->update(['status' => ChatConversation::STATUS_CLOSED]);

        $response = $this->actingAs($customer)
            ->getJson('/chat/conversations')
            ->assertOk()
            ->assertJsonPath('view', 'all')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.active', 1)
            ->assertJsonPath('counts.completed', 1);

        $this->assertEqualsCanonicalizing(
            [$active->id, $closed->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_unrelated_customer_cannot_read_or_send_to_a_conversation(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $conversation = $this->conversationFor($owner);

        $this->actingAs($stranger)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertForbidden();
        $this->actingAs($stranger)
            ->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'Tin nhắn lạ'])
            ->assertForbidden();
    }

    public function test_admin_reply_and_read_receipt_are_visible_to_the_customer(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ChatsAssign,
            AppPermission::ChatsManage,
        ]);
        $admin->update(['chat_display_name' => 'Hỗ trợ viên An']);
        $conversation = $this->conversationFor($customer);

        $customerMessageId = $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'Admin xem giúp mình nhé'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $customerMessageId,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/admin/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Mình đang kiểm tra đơn cho bạn.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_kind', ChatMessage::SENDER_AGENT)
            ->assertJsonPath('data.sender.username', $admin->username)
            ->assertJsonPath('data.sender.display_name', 'Hỗ trợ viên An');

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'assigned_to_id' => $admin->id,
            'status' => ChatConversation::STATUS_WAITING_CUSTOMER,
        ]);

        $customerView = $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.assignee.username', $admin->username)
            ->assertJsonPath('data.assignee.display_name', 'Hỗ trợ viên An');

        $customerMessage = collect($customerView->json('messages'))
            ->firstWhere('id', $customerMessageId);

        $this->assertSame($admin->id, $customerMessage['seen_by'][0]['id']);
        $this->assertSame($admin->username, $customerMessage['seen_by'][0]['username']);
        $this->assertSame('Hỗ trợ viên An', $customerMessage['seen_by'][0]['display_name']);
    }

    public function test_read_receipt_is_only_broadcast_when_the_pointer_advances(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $message = $conversation->messages()->create([
            'sender_id' => null,
            'sender_kind' => ChatMessage::SENDER_SYSTEM,
            'type' => ChatMessage::TYPE_SYSTEM,
            'body' => 'Thông báo cần đọc',
            'is_internal' => false,
        ]);
        Event::fake([ChatReadUpdated::class]);

        foreach (range(1, 2) as $_attempt) {
            $this->actingAs($customer)
                ->patchJson("/chat/conversations/{$conversation->id}/read", [
                    'last_read_message_id' => $message->id,
                ])
                ->assertOk();
        }

        Event::assertDispatchedTimes(ChatReadUpdated::class, 1);
    }

    public function test_service_receiver_ctv_can_open_chat_but_another_ctv_cannot(): void
    {
        $customer = User::factory()->create();
        $receiver = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $otherCtv = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $admin = $this->agent('admin', [AppPermission::ChatsView, AppPermission::ChatsAssign]);
        $service = Service::query()->create(['name' => 'Đi làm nhiệm vụ', 'status' => true]);
        $order = $this->serviceOrder($customer, $service, $receiver);

        $conversationId = $this->actingAs($customer)
            ->postJson('/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assignee.id', $receiver->id)
            ->json('data.id');

        $this->actingAs($receiver)
            ->getJson("/admin/chat/conversations/{$conversationId}")
            ->assertOk();
        $this->actingAs($otherCtv)
            ->getJson("/admin/chat/conversations/{$conversationId}")
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/admin/chat/conversations/{$conversationId}/assign", [
                'assigned_to_id' => $otherCtv->id,
            ])
            ->assertOk();

        $this->actingAs($otherCtv)
            ->getJson("/admin/chat/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.subject.label', 'Đơn dịch vụ #'.$order->id);
        $this->actingAs($receiver)
            ->getJson("/admin/chat/conversations/{$conversationId}")
            ->assertForbidden();
    }

    public function test_user_with_direct_view_all_permission_can_see_every_conversation_without_role(): void
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo([
            AppPermission::ChatsView->value,
            AppPermission::ChatsViewAll->value,
        ]);
        $assigned = $this->conversationFor(User::factory()->create());
        $assigned->update(['assigned_to_id' => $viewer->id]);
        $unrelated = $this->conversationFor(User::factory()->create());

        $this->assertTrue($viewer->getRoleNames()->isEmpty());
        $this->assertFalse($viewer->canViewAllAdminData());
        $this->assertTrue($viewer->canViewAllChats());

        $this->actingAs($viewer)
            ->get('/admin/chats')
            ->assertOk();

        $response = $this->actingAs($viewer)
            ->getJson('/admin/chat/conversations?view=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$assigned->id, $unrelated->id],
            collect($response->json('data'))->pluck('id')->all(),
        );

        $this->actingAs($viewer)
            ->getJson("/admin/chat/conversations/{$unrelated->id}")
            ->assertOk();
    }

    public function test_ctv_without_view_all_permission_stays_scoped_even_with_other_chat_permissions(): void
    {
        $ctv = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ChatsAssign,
            AppPermission::ChatsManage,
        ]);
        $assigned = $this->conversationFor(User::factory()->create());
        $assigned->update(['assigned_to_id' => $ctv->id]);
        $unrelated = $this->conversationFor(User::factory()->create());
        $assignee = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);

        $this->assertFalse($ctv->canViewAllChats());

        $this->actingAs($ctv)
            ->getJson('/admin/chat/conversations?view=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);

        $this->actingAs($ctv)
            ->getJson("/admin/chat/conversations/{$unrelated->id}")
            ->assertForbidden();

        $this->actingAs($ctv)
            ->patchJson("/admin/chat/conversations/{$unrelated->id}/assign", [
                'assigned_to_id' => $assignee->id,
            ])
            ->assertForbidden();
    }

    public function test_global_chat_assignment_still_requires_assign_permission(): void
    {
        $conversation = $this->conversationFor(User::factory()->create());
        $assignee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $assignee->givePermissionTo([
            AppPermission::ChatsView->value,
            AppPermission::ChatsReply->value,
        ]);
        $viewer = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsViewAll,
        ]);
        $dispatcher = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsViewAll,
            AppPermission::ChatsAssign,
        ]);

        $this->assertTrue($assignee->getRoleNames()->isEmpty());

        $this->actingAs($viewer)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/assign", [
                'assigned_to_id' => $assignee->id,
            ])
            ->assertForbidden();

        $this->actingAs($dispatcher)
            ->getJson('/admin/chat/agents')
            ->assertOk()
            ->assertJsonFragment(['id' => $assignee->id]);

        $this->actingAs($dispatcher)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/assign", [
                'assigned_to_id' => $assignee->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $assignee->id);
    }

    public function test_admin_needs_view_permission_in_addition_to_assign_permission(): void
    {
        $customer = User::factory()->create();
        $assignOnlyAdmin = $this->agent('admin', [AppPermission::ChatsAssign]);
        $assignee = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $conversation = $this->conversationFor($customer);

        $this->actingAs($assignOnlyAdmin)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/assign", [
                'assigned_to_id' => $assignee->id,
            ])
            ->assertForbidden();

        $this->assertNull($conversation->refresh()->assigned_to_id);
    }

    public function test_internal_note_is_hidden_from_customer_and_does_not_change_public_status(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ChatsManage,
        ]);
        $conversation = $this->conversationFor($customer);

        $internalMessageId = $this->actingAs($admin)
            ->postJson("/admin/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Cần kiểm tra log nội bộ.',
                'is_internal' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_internal', true)
            ->json('data.id');

        $this->assertSame(ChatConversation::STATUS_WAITING_AGENT, $conversation->refresh()->status);
        $this->assertNull($conversation->last_message_id);

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(0, 'messages');

        $this->actingAs($admin)
            ->getJson('/admin/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.latest_message_id', $internalMessageId);
        $this->actingAs($customer)
            ->getJson('/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.latest_message_id', null);
    }

    public function test_closed_conversation_rejects_new_messages(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $conversation->update(['status' => ChatConversation::STATUS_CLOSED]);

        $this->actingAs($customer)
            ->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'Gửi sau khi đóng'])
            ->assertForbidden();
    }

    public function test_user_and_agent_chat_pages_render_the_expected_workspaces(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [AppPermission::ChatsView]);

        $this->actingAs($customer)
            ->get('/messages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Messages/Index'));

        $this->actingAs($admin)
            ->get('/admin/chats')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Chats/Index'));
    }

    public function test_passport_frontend_can_use_the_shared_chat_api(): void
    {
        $customer = User::factory()->create();
        Passport::actingAs($customer, ['chat:read', 'chat:write']);

        $this->postJson('/api/chat/conversations/resolve', ['category' => 'general'])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id);

        $this->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_passport_chat_api_rejects_tokens_without_chat_scope(): void
    {
        Passport::actingAs(User::factory()->create(), ['profile:read']);

        $this->getJson('/api/chat/conversations')->assertForbidden();
        $this->postJson('/api/chat/conversations/resolve', ['category' => 'general'])->assertForbidden();
    }

    public function test_realtime_message_payload_is_recipient_neutral_and_broadcasts_to_every_connection(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $clientMessageId = (string) Str::uuid();
        Event::fake([ChatMessageSent::class, ChatInboxUpdated::class, ChatReadUpdated::class]);

        $this->actingAs($customer)
            ->withHeader('X-Socket-ID', '123.456')
            ->postJson("/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Tin realtime',
                'client_message_id' => $clientMessageId,
            ])
            ->assertCreated();

        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event): bool => $event->socket === null
            && ! array_key_exists('is_mine', $event->message)
            && $event->message['client_message_id'] === $clientMessageId
            && $event->conversation['id'] === (int) $conversation->id
            && $event->conversation['status'] === ChatConversation::STATUS_WAITING_AGENT
            && array_keys($event->broadcastWith()) === ['message', 'conversation']);
        Event::assertDispatched(ChatReadUpdated::class, fn (ChatReadUpdated $event): bool => $event->socket === null
            && $event->conversationId === (int) $conversation->id
            && $event->reader['id'] === (int) $customer->id
            && $event->reader['kind'] === ChatParticipant::ROLE_CUSTOMER);
        Event::assertNotDispatched(ChatInboxUpdated::class);
    }

    public function test_resource_permissions_follow_policy_after_agent_loses_reply_permission(): void
    {
        $customer = User::factory()->create();
        $agent = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $conversation = $this->conversationFor($customer);
        $conversation->update(['assigned_to_id' => $agent->id]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $agent->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'joined_at' => now(),
        ]);
        $agent->revokePermissionTo(AppPermission::ChatsReply->value);

        $this->actingAs($agent)
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.reply', false)
            ->assertJsonPath('data.permissions.manage', false);
    }

    public function test_locked_agent_cannot_open_or_read_chat_with_an_existing_session(): void
    {
        $customer = User::factory()->create();
        $agent = $this->agent('admin', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $this->conversationFor($customer);
        $agent->update(['status' => User::STATUS_LOCKED]);

        $this->actingAs($agent)->get('/admin/chats')->assertForbidden();
        $this->actingAs($agent)->getJson('/admin/chat/conversations')->assertForbidden();
    }

    public function test_non_active_agent_cannot_read_the_chat_inbox(): void
    {
        $agent = $this->agent('admin', [AppPermission::ChatsView]);
        $agent->update(['status' => User::STATUS_PENDING]);

        $this->actingAs($agent)->getJson('/admin/chat/conversations')->assertForbidden();
    }

    public function test_locked_customer_cannot_use_chat_with_an_existing_api_token(): void
    {
        $customer = User::factory()->create(['status' => User::STATUS_LOCKED]);
        Passport::actingAs($customer, ['chat:read', 'chat:write']);

        $this->getJson('/api/chat/conversations')->assertForbidden();
        $this->postJson('/api/chat/conversations/resolve', ['category' => 'general'])->assertForbidden();
    }

    public function test_reassigning_chat_revokes_the_former_ctv_participation(): void
    {
        $customer = User::factory()->create();
        $manager = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsAssign,
        ]);
        $firstCtv = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $secondCtv = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $conversation = $this->conversationFor($customer);
        $conversation->update(['assigned_to_id' => $firstCtv->id]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $firstCtv->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'joined_at' => now(),
        ]);

        $this->actingAs($manager)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/assign", [
                'assigned_to_id' => $secondCtv->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $secondCtv->id);

        $this->assertNotNull(ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $firstCtv->id)
            ->value('left_at'));
        $this->actingAs($firstCtv)
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertForbidden();
        $this->actingAs($secondCtv)
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertOk();
    }

    public function test_service_rechecks_authorization_after_lock_and_cannot_revive_a_former_ctv(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [AppPermission::ChatsView, AppPermission::ChatsAssign]);
        $formerCtv = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $currentCtv = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $conversation = $this->conversationFor($customer);
        $conversation->update(['assigned_to_id' => $formerCtv->id]);
        $message = $conversation->messages()->create([
            'sender_id' => $customer->id,
            'sender_kind' => ChatMessage::SENDER_CUSTOMER,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'Tin cần đọc',
            'is_internal' => false,
        ]);
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $formerCtv->id,
            'role' => ChatParticipant::ROLE_AGENT,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/assign", [
                'assigned_to_id' => $currentCtv->id,
            ])
            ->assertOk();

        $chat = app(ChatManager::class);
        foreach ([
            fn () => $chat->send($conversation, $formerCtv, ['body' => 'Không còn quyền gửi']),
            fn () => $chat->markRead($conversation, $formerCtv, $message->id),
            fn () => $chat->updateStatus($conversation, $formerCtv, ChatConversation::STATUS_RESOLVED),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Former CTV mutation should have been rejected after the row lock.');
            } catch (AuthorizationException) {
                // Expected: the service checks the fresh locked conversation, not stale controller state.
            }
        }

        $this->assertNotNull(ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $formerCtv->id)
            ->value('left_at'));
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(ChatConversation::STATUS_WAITING_AGENT, $conversation->refresh()->status);
    }

    public function test_assigned_agent_can_view_the_related_service_order_from_chat(): void
    {
        $customer = User::factory()->create();
        $agent = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $service = Service::query()->create(['name' => 'Săn đệ tử', 'status' => true]);
        $order = $this->serviceOrder($customer, $service, $agent);
        $conversation = ChatConversation::query()->create([
            'customer_id' => $customer->id,
            'category' => ChatConversation::CATEGORY_ORDER_SUPPORT,
            'subject_type' => 'service_order',
            'subject_id' => $order->id,
            'assigned_to_id' => $agent->id,
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'priority' => ChatConversation::PRIORITY_NORMAL,
        ]);

        $this->actingAs($agent)
            ->getJson("/admin/chat/conversations/{$conversation->id}/subject")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.type', 'service_order')
            ->assertJsonPath('data.fields.1.value', 'Săn đệ tử')
            ->assertJsonPath('data.fields.2.value', 'account-test')
            ->assertJsonMissingPath('data.password');
    }

    public function test_assigned_agent_can_view_a_related_gold_order_from_chat(): void
    {
        $customer = User::factory()->create();
        $agent = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsReply]);
        $server = Server::query()->create([
            'name' => 'server-1',
            'name_view' => 'Vũ Trụ 2',
            'status' => true,
        ]);
        $order = GoldTransaction::query()->create([
            'type' => GoldTransaction::TYPE_ORDER,
            'user_id' => $customer->id,
            'server_id' => $server->id,
            'character_name' => 'em8ahsb',
            'amount_vnd' => 10000,
            'gold_qty' => 1000,
            'gold_bar_qty' => 0,
            'pure_gold_qty' => 1000,
            'price_at_transaction' => 10,
            'status' => GoldTransaction::STATUS_CANCELLED,
            'updated_by' => 'web',
        ]);
        $conversation = ChatConversation::query()->create([
            'customer_id' => $customer->id,
            'category' => ChatConversation::CATEGORY_ORDER_SUPPORT,
            'subject_type' => 'gold_transaction',
            'subject_id' => $order->id,
            'assigned_to_id' => $agent->id,
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'priority' => ChatConversation::PRIORITY_NORMAL,
        ]);

        $this->actingAs($agent)
            ->getJson("/admin/chat/conversations/{$conversation->id}/subject")
            ->assertOk()
            ->assertJsonPath('data.type', 'gold_transaction')
            ->assertJsonPath('data.label', "Đơn vàng #{$order->id}")
            ->assertJsonPath('data.fields.1.value', 'Vũ Trụ 2')
            ->assertJsonPath('data.fields.2.value', 'em8ahsb');
    }

    public function test_internal_notes_are_separated_from_public_messages_and_paginated_for_agents(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsManage,
        ]);
        $conversation = $this->conversationFor($customer);
        $publicMessage = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $customer->id,
            'sender_kind' => ChatMessage::SENDER_CUSTOMER,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'Tin nhắn khách hàng.',
            'is_internal' => false,
        ]);
        $notes = collect(range(1, 35))->map(fn (int $number) => ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
            'sender_kind' => ChatMessage::SENDER_AGENT,
            'type' => ChatMessage::TYPE_INTERNAL_NOTE,
            'body' => "Ghi chú {$number}",
            'is_internal' => true,
        ]));

        $this->actingAs($admin)
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $publicMessage->id)
            ->assertJsonCount(30, 'internal_notes')
            ->assertJsonPath('internal_notes.0.id', $notes[5]->id)
            ->assertJsonPath('internal_notes.29.id', $notes[34]->id)
            ->assertJsonPath('internal_notes_has_more', true)
            ->assertJsonPath('data.internal_notes_count', 35);

        $this->actingAs($admin)
            ->getJson("/admin/chat/conversations/{$conversation->id}/notes?before={$notes[5]->id}&limit=30")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', $notes[0]->id)
            ->assertJsonPath('data.4.id', $notes[4]->id)
            ->assertJsonPath('count', 35)
            ->assertJsonPath('has_more', false);

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonMissingPath('internal_notes')
            ->assertJsonMissingPath('data.internal_notes_count')
            ->assertJsonMissingPath('data.pinned_note');
    }

    public function test_only_manage_agents_can_pin_a_note_from_the_same_conversation(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsManage,
        ]);
        $viewer = $this->agent('ctv', [AppPermission::ChatsView, AppPermission::ChatsViewAll]);
        $conversation = $this->conversationFor($customer);
        $otherConversation = $this->conversationFor(User::factory()->create());
        $note = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
            'sender_kind' => ChatMessage::SENDER_AGENT,
            'type' => ChatMessage::TYPE_INTERNAL_NOTE,
            'body' => 'Ưu tiên kiểm tra lịch sử đơn.',
            'is_internal' => true,
        ]);
        $otherNote = ChatMessage::query()->create([
            'conversation_id' => $otherConversation->id,
            'sender_id' => $admin->id,
            'sender_kind' => ChatMessage::SENDER_AGENT,
            'type' => ChatMessage::TYPE_INTERNAL_NOTE,
            'body' => 'Ghi chú của hội thoại khác.',
            'is_internal' => true,
        ]);
        $publicMessage = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $customer->id,
            'sender_kind' => ChatMessage::SENDER_CUSTOMER,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'Tin công khai.',
            'is_internal' => false,
        ]);

        $this->actingAs($viewer)
            ->getJson("/admin/chat/conversations/{$conversation->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.id', $note->id);
        $this->actingAs($viewer)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/pinned-note", [
                'pinned_note_id' => $note->id,
            ])
            ->assertForbidden();

        Event::fake([ChatInboxUpdated::class]);

        $this->actingAs($admin)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/pinned-note", [
                'pinned_note_id' => $note->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.pinned_note.id', $note->id)
            ->assertJsonPath('data.internal_notes_count', 1);

        $this->assertSame($note->id, $conversation->refresh()->pinned_note_id);
        Event::assertDispatched(ChatInboxUpdated::class, fn (ChatInboxUpdated $event): bool => $event->action === 'note_pinned'
            && ($event->conversation['pinned_note']['id'] ?? null) === $note->id
            && ! array_key_exists('is_mine', $event->conversation['pinned_note'])
            && in_array($admin->id, $event->recipientIds, true)
            && ! in_array($customer->id, $event->recipientIds, true));

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.pinned_note');

        foreach ([$otherNote, $publicMessage] as $invalidNote) {
            $this->actingAs($admin)
                ->patchJson("/admin/chat/conversations/{$conversation->id}/pinned-note", [
                    'pinned_note_id' => $invalidNote->id,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('pinned_note_id');
        }

        $this->actingAs($admin)
            ->patchJson("/admin/chat/conversations/{$conversation->id}/pinned-note", [
                'pinned_note_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.pinned_note', null);
        $this->assertNull($conversation->refresh()->pinned_note_id);
    }

    private function conversationFor(User $customer): ChatConversation
    {
        return ChatConversation::query()->create([
            'customer_id' => $customer->id,
            'category' => ChatConversation::CATEGORY_GENERAL,
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'priority' => ChatConversation::PRIORITY_NORMAL,
        ]);
    }

    /** @param list<AppPermission> $permissions */
    private function agent(string $role, array $permissions): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);
        $user->givePermissionTo(array_map(fn (AppPermission $permission) => $permission->value, $permissions));

        return $user;
    }

    private function serviceOrder(
        User $customer,
        Service $service,
        ?User $receiver = null,
        string $status = 'approved',
    ): ServiceOrder {
        return ServiceOrder::withoutReceiverOwnedScope()->create([
            'service_id' => $service->id,
            'user_id' => $customer->id,
            'receiver_id' => $receiver?->id,
            'service_price' => 50000,
            'account' => 'account-test',
            'password' => 'secret-not-for-chat',
            'description' => 'Đơn kiểm thử chat.',
            'status' => $status,
        ]);
    }
}
