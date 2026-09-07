<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Events\ChatInboxUpdated;
use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatOutboundConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ServiceOrdersView,
            AppPermission::ServiceOrdersProcess,
        ] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (['admin', 'super-admin', 'ctv'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_resolve_a_customer_chat_once_with_an_audited_system_message(): void
    {
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
        ]);
        $customer = User::factory()->create();
        Event::fake([ChatMessageSent::class, ChatInboxUpdated::class]);

        $first = $this->actingAs($admin)->postJson('/admin/chat/conversations/resolve', [
            'customer_id' => $customer->id,
            'source_app' => 'admin-chat',
            'source_url' => 'https://example.test/admin/chats',
        ]);

        $first
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.avatar', null)
            ->assertJsonPath('data.assignee.id', $admin->id)
            ->assertJsonPath('data.assignee.avatar', null)
            ->assertJsonPath('data.status', ChatConversation::STATUS_WAITING_CUSTOMER)
            ->assertJsonPath('data.last_message.type', ChatMessage::TYPE_SYSTEM)
            ->assertJsonPath('entered_active', true);

        $conversationId = (int) $first->json('data.id');

        $this->actingAs($admin)
            ->postJson('/admin/chat/conversations/resolve', ['customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId)
            ->assertJsonPath('entered_active', false);

        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertDatabaseHas('chat_conversation_events', [
            'conversation_id' => $conversationId,
            'actor_id' => $admin->id,
            'event_type' => 'agent_initiated',
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $customer->id,
            'role' => 'customer',
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $admin->id,
            'role' => 'agent',
        ]);

        Event::assertDispatchedTimes(ChatMessageSent::class, 1);
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event): bool => $event->conversationId === $conversationId
            && in_array($customer->id, $event->recipientIds, true)
            && $event->message['type'] === ChatMessage::TYPE_SYSTEM);
    }

    public function test_admin_outbound_reopens_a_resolved_chat_once(): void
    {
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
        ]);
        $customer = User::factory()->create();

        $first = $this->actingAs($admin)->postJson('/admin/chat/conversations/resolve', [
            'customer_id' => $customer->id,
        ])->assertCreated();
        $conversation = ChatConversation::query()->findOrFail($first->json('data.id'));
        $conversation->update([
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/chat/conversations/resolve', ['customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.status', ChatConversation::STATUS_WAITING_CUSTOMER)
            ->assertJsonPath('data.last_message.type', ChatMessage::TYPE_SYSTEM)
            ->assertJsonPath('entered_active', true);

        $this->assertSame(2, $conversation->messages()->count());
        $this->assertDatabaseHas('chat_conversation_events', [
            'conversation_id' => $conversation->id,
            'actor_id' => $admin->id,
            'event_type' => 'reopened',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/chat/conversations/resolve', ['customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('entered_active', false);

        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_ctv_can_only_resolve_the_active_service_order_assigned_to_them(): void
    {
        $receiver = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ServiceOrdersView,
        ]);
        $otherCtv = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ServiceOrdersView,
        ]);
        $customer = User::factory()->create();
        $order = $this->serviceOrder($customer, $receiver);

        $first = $this->actingAs($receiver)->postJson('/admin/chat/conversations/resolve', [
            'subject_type' => 'service_order',
            'subject_id' => $order->id,
            'source_app' => 'admin-service-orders',
        ]);

        $first
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.assignee.id', $receiver->id)
            ->assertJsonPath('data.subject_type', 'service_order')
            ->assertJsonPath('data.subject_id', $order->id)
            ->assertJsonPath('data.status', ChatConversation::STATUS_WAITING_CUSTOMER);

        $conversationId = (int) $first->json('data.id');

        $this->actingAs($receiver)
            ->postJson('/admin/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId);

        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 1);

        $this->actingAs($otherCtv)
            ->postJson('/admin/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertForbidden();

        $this->actingAs($receiver)
            ->postJson('/admin/chat/conversations/resolve', ['customer_id' => $customer->id])
            ->assertForbidden();

        $order->update(['status' => 'completed']);
        $this->actingAs($receiver)
            ->postJson('/admin/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertForbidden();
    }

    public function test_outbound_resolution_requires_both_chat_permissions_and_matching_order_customer(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $adminWithoutReply = $this->agent('admin', [AppPermission::ChatsView]);
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
        ]);
        $order = $this->serviceOrder($customer);

        $this->actingAs($adminWithoutReply)
            ->postJson('/admin/chat/conversations/resolve', ['customer_id' => $customer->id])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/admin/chat/conversations/resolve', [
                'customer_id' => $otherCustomer->id,
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $this->actingAs($admin)
            ->postJson('/admin/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.assignee.id', $admin->id);
    }

    public function test_resolving_an_existing_order_chat_moves_it_to_the_current_receiver_without_new_message(): void
    {
        $customer = User::factory()->create();
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
        ]);
        $receiver = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ServiceOrdersProcess,
        ]);
        $order = $this->serviceOrder($customer, $receiver);
        $conversation = ChatConversation::query()->create([
            'customer_id' => $customer->id,
            'category' => ChatConversation::CATEGORY_ORDER_SUPPORT,
            'subject_type' => 'service_order',
            'subject_id' => $order->id,
            'assigned_to_id' => $admin->id,
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'priority' => ChatConversation::PRIORITY_NORMAL,
        ]);

        $this->actingAs($receiver)
            ->postJson('/admin/chat/conversations/resolve', [
                'subject_type' => 'service_order',
                'subject_id' => $order->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.assignee.id', $receiver->id);

        $this->assertSame(0, $conversation->messages()->count());
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'assigned_to_id' => $receiver->id,
        ]);
    }

    public function test_only_admins_with_chat_permissions_can_search_active_customers(): void
    {
        $admin = $this->agent('admin', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
        ]);
        $ctv = $this->agent('ctv', [
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
        ]);
        $ctv->update(['username' => 'lookup_staff']);
        $matching = User::factory()->create([
            'username' => 'khach_can_tim',
            'email' => 'lookup@example.test',
        ]);
        User::factory()->create([
            'username' => 'khach_bi_khoa',
            'status' => User::STATUS_LOCKED,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/chat/customers?search=lookup')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.username', 'khach_can_tim')
            ->assertJsonMissingPath('data.0.email');

        $this->actingAs($ctv)
            ->getJson('/admin/chat/customers?search=khach')
            ->assertForbidden();
    }

    /** @param list<AppPermission> $permissions */
    private function agent(string $role, array $permissions): User
    {
        $agent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $agent->assignRole($role);
        $agent->givePermissionTo(array_map(
            static fn (AppPermission $permission): string => $permission->value,
            $permissions,
        ));

        return $agent;
    }

    private function serviceOrder(
        User $customer,
        ?User $receiver = null,
        string $status = 'approved',
    ): ServiceOrder {
        $service = Service::query()->create([
            'name' => 'Dịch vụ outbound chat',
            'default_price' => 5000,
            'status' => true,
        ]);

        return ServiceOrder::withoutReceiverOwnedScope()->create([
            'service_id' => $service->id,
            'user_id' => $customer->id,
            'receiver_id' => $receiver?->id,
            'service_price' => 5000,
            'account' => 'outbound-test',
            'password' => 'secret',
            'description' => 'Đơn dùng kiểm thử outbound chat.',
            'status' => $status,
        ]);
    }
}
