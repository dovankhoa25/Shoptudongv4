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
        $this->assertSame(2, $conversation->messages()->count());
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
            ->assertJsonPath('data.sender_kind', ChatMessage::SENDER_AGENT);

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'assigned_to_id' => $admin->id,
            'status' => ChatConversation::STATUS_WAITING_CUSTOMER,
        ]);

        $customerView = $this->actingAs($customer)
            ->getJson("/chat/conversations/{$conversation->id}")
            ->assertOk();

        $customerMessage = collect($customerView->json('messages'))
            ->firstWhere('id', $customerMessageId);

        $this->assertSame($admin->id, $customerMessage['seen_by'][0]['id']);
        $this->assertSame($admin->username, $customerMessage['seen_by'][0]['username']);
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
