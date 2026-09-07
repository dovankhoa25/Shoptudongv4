<?php

namespace App\Services\Chat;

use App\Enums\Permission;
use App\Models\ChatConversation;
use App\Models\ChatConversationEvent;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChatManager
{
    public function __construct(private readonly ChatSubjectResolver $subjects) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: ChatConversation, 1: bool, 2: int|null, 3: ChatMessage|null}
     */
    public function resolve(User $customer, array $attributes): array
    {
        return DB::transaction(function () use ($customer, $attributes): array {
            [$conversation, $created, $previousAssigneeId] = $this->resolveConversation(
                $customer,
                $attributes,
                $customer,
            );
            $welcomeMessage = null;

            if ($created) {
                $welcomeMessage = $conversation->messages()->create([
                    'sender_id' => null,
                    'sender_kind' => ChatMessage::SENDER_SYSTEM,
                    'type' => ChatMessage::TYPE_SYSTEM,
                    'body' => (string) config('chat.welcome_message'),
                    'metadata' => [
                        'event' => ChatMessage::EVENT_WELCOME_MESSAGE,
                        'automated' => true,
                    ],
                    'is_internal' => false,
                ]);

                ChatParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $customer->getKey())
                    ->update([
                        'last_read_message_id' => $welcomeMessage->id,
                        'last_read_at' => now(),
                    ]);
            }

            return [$conversation->fresh(), $created, $previousAssigneeId, $welcomeMessage];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: ChatConversation, 1: bool, 2: int|null, 3: string|null, 4: bool}
     */
    private function resolveConversation(User $customer, array $attributes, User $initiator): array
    {
        $type = $attributes['subject_type'] ?? null;
        $subjectId = isset($attributes['subject_id']) ? (int) $attributes['subject_id'] : null;

        return DB::transaction(function () use ($customer, $attributes, $type, $subjectId, $initiator): array {
            $subject = $type && $subjectId
                ? $this->subjects->resolveOwned($type, $subjectId, $customer, true)
                : null;
            User::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $suggestedAssigneeId = $type && $subject
                ? $this->subjects->suggestedAssigneeId($type, $subject)
                : null;
            $assigneeId = $this->eligibleAssigneeId($suggestedAssigneeId, $customer);
            $conversation = null;
            $previousAssigneeId = null;
            $resolutionAction = null;
            $enteredActive = false;

            if ($type) {
                $conversation = ChatConversation::query()
                    ->where('subject_type', $type)
                    ->where('subject_id', $subjectId)
                    ->lockForUpdate()
                    ->first();

                if ($conversation && (int) $conversation->customer_id !== (int) $customer->getKey()) {
                    throw ValidationException::withMessages([
                        'subject_id' => 'Không thể mở hội thoại cho đơn hàng này.',
                    ]);
                }

                if (! $conversation) {
                    $generalConversation = ChatConversation::query()
                        ->where('customer_id', $customer->getKey())
                        ->whereNull('subject_type')
                        ->whereNull('subject_id')
                        ->where('status', '!=', ChatConversation::STATUS_CLOSED)
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

                    if ($generalConversation) {
                        // Re-check after locking the general chat to avoid attaching the
                        // same order twice when two resolve requests arrive together.
                        $conversation = ChatConversation::query()
                            ->where('subject_type', $type)
                            ->where('subject_id', $subjectId)
                            ->lockForUpdate()
                            ->first();

                        if (! $conversation) {
                            $oldStatus = $generalConversation->status;
                            $oldAssigneeId = $generalConversation->assigned_to_id;
                            $newAssigneeId = $assigneeId ?: $oldAssigneeId;

                            $generalConversation->update([
                                'category' => ChatConversation::CATEGORY_ORDER_SUPPORT,
                                'subject_type' => $type,
                                'subject_id' => $subjectId,
                                'status' => ChatConversation::STATUS_WAITING_AGENT,
                                'resolved_by' => null,
                                'resolved_at' => null,
                            ]);

                            ChatConversationEvent::query()->create([
                                'conversation_id' => $generalConversation->id,
                                'actor_id' => $initiator->getKey(),
                                'event_type' => 'subject_attached',
                                'old_value' => [
                                    'subject_type' => null,
                                    'subject_id' => null,
                                    'status' => $oldStatus,
                                ],
                                'new_value' => [
                                    'subject_type' => $type,
                                    'subject_id' => $subjectId,
                                    'status' => ChatConversation::STATUS_WAITING_AGENT,
                                ],
                            ]);

                            if ($this->changeAssignee($generalConversation, $initiator, $newAssigneeId)) {
                                $previousAssigneeId = $oldAssigneeId ? (int) $oldAssigneeId : null;
                            }

                            $conversation = $generalConversation;
                            $resolutionAction = 'subject_attached';
                            $enteredActive = ! in_array($oldStatus, [
                                ChatConversation::STATUS_WAITING_AGENT,
                                ChatConversation::STATUS_WAITING_CUSTOMER,
                            ], true);
                        }
                    }
                }
            } else {
                $conversation = ChatConversation::query()
                    ->where('customer_id', $customer->getKey())
                    ->whereNull('subject_type')
                    ->whereNull('subject_id')
                    ->where('status', '!=', ChatConversation::STATUS_CLOSED)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
            }

            $created = false;

            if (! $conversation) {
                $values = [
                    'customer_id' => $customer->getKey(),
                    'category' => $type ? ChatConversation::CATEGORY_ORDER_SUPPORT : ($attributes['category'] ?? ChatConversation::CATEGORY_GENERAL),
                    'assigned_to_id' => $assigneeId,
                    'status' => ChatConversation::STATUS_WAITING_AGENT,
                    'priority' => ChatConversation::PRIORITY_NORMAL,
                    'source_app' => $attributes['source_app'] ?? null,
                    'source_url' => $attributes['source_url'] ?? null,
                ];
                $conversation = $type
                    ? ChatConversation::query()->createOrFirst(
                        ['subject_type' => $type, 'subject_id' => $subjectId],
                        $values,
                    )
                    : ChatConversation::query()->create([
                        ...$values,
                        'subject_type' => null,
                        'subject_id' => null,
                    ]);
                $created = $conversation->wasRecentlyCreated;

                if ($created) {
                    $resolutionAction = 'created';
                    $enteredActive = true;
                    ChatConversationEvent::query()->create([
                        'conversation_id' => $conversation->id,
                        'actor_id' => $initiator->getKey(),
                        'event_type' => 'created',
                        'new_value' => [
                            'subject_type' => $type,
                            'subject_id' => $subjectId,
                            'assigned_to_id' => $assigneeId,
                        ],
                    ]);
                }
            }

            if (! $created && $conversation->status === ChatConversation::STATUS_CLOSED) {
                $resolutionAction = 'reopened';
                $enteredActive = true;
                $conversation->update([
                    'status' => ChatConversation::STATUS_WAITING_AGENT,
                    'resolved_by' => null,
                    'resolved_at' => null,
                ]);

                ChatConversationEvent::query()->create([
                    'conversation_id' => $conversation->id,
                    'actor_id' => $initiator->getKey(),
                    'event_type' => 'reopened',
                    'old_value' => ['status' => ChatConversation::STATUS_CLOSED],
                    'new_value' => ['status' => ChatConversation::STATUS_WAITING_AGENT],
                ]);
            }

            ChatParticipant::query()->updateOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $customer->getKey()],
                ['role' => ChatParticipant::ROLE_CUSTOMER, 'joined_at' => now(), 'left_at' => null],
            );

            if ($conversation->assigned_to_id) {
                ChatParticipant::query()->updateOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $conversation->assigned_to_id],
                    ['role' => ChatParticipant::ROLE_AGENT, 'joined_at' => now(), 'left_at' => null],
                );
            }

            return [$conversation->fresh(), $created, $previousAssigneeId, $resolutionAction, $enteredActive];
        });
    }

    /**
     * Resolve an agent-started conversation and add its first visible system
     * message in the same transaction. Repeating the request for an active
     * conversation only returns that conversation and does not add noise.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: ChatConversation, 1: bool, 2: ChatMessage|null, 3: int|null, 4: bool}
     */
    public function resolveForAgent(User $actor, array $attributes): array
    {
        return DB::transaction(function () use ($actor, $attributes): array {
            $actor = User::query()->findOrFail($actor->getKey());
            $this->authorizeOutboundActor($actor);

            $type = $attributes['subject_type'] ?? null;
            $subjectId = isset($attributes['subject_id']) ? (int) $attributes['subject_id'] : null;
            $order = null;

            if ($type !== null) {
                if ($type !== 'service_order' || ! $subjectId) {
                    throw ValidationException::withMessages([
                        'subject_type' => 'Hiện chỉ hỗ trợ chủ động mở chat từ đơn dịch vụ.',
                    ]);
                }

                $order = ServiceOrder::withoutReceiverOwnedScope()
                    ->whereKey($subjectId)
                    ->lockForUpdate()
                    ->first();

                if (! $order) {
                    throw ValidationException::withMessages([
                        'subject_id' => 'Không tìm thấy đơn dịch vụ.',
                    ]);
                }

                $customerId = (int) $order->user_id;
                if (isset($attributes['customer_id'])
                    && (int) $attributes['customer_id'] !== $customerId) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'Khách hàng không khớp với đơn dịch vụ.',
                    ]);
                }

                $this->authorizeOutboundServiceOrder($actor, $order);
            } else {
                if (! $actor->canViewAllAdminData()) {
                    throw new AuthorizationException('CTV chỉ có thể chủ động chat từ đơn dịch vụ đang nhận.');
                }

                $customerId = isset($attributes['customer_id'])
                    ? (int) $attributes['customer_id']
                    : 0;
            }

            $customer = User::query()
                ->whereKey($customerId)
                ->where('status', User::STATUS_ACTIVE)
                ->first();

            if (! $customer || $customer->isLocked()) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Không tìm thấy khách hàng đang hoạt động.',
                ]);
            }

            if ((int) $customer->getKey() === (int) $actor->getKey()) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Không thể tạo cuộc trò chuyện hỗ trợ với chính mình.',
                ]);
            }

            if (! $type && $customer->isChatAgent()) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Hãy chọn một tài khoản khách hàng.',
                ]);
            }

            $attributes['customer_id'] = $customer->getKey();
            [$conversation, $created, $previousAssigneeId, $resolutionAction, $enteredActive] = $this->resolveConversation(
                $customer,
                $attributes,
                $actor,
            );

            if ($resolutionAction === null
                && $conversation->status === ChatConversation::STATUS_RESOLVED) {
                $conversation->update([
                    'status' => ChatConversation::STATUS_WAITING_AGENT,
                    'resolved_by' => null,
                    'resolved_at' => null,
                ]);

                ChatConversationEvent::query()->create([
                    'conversation_id' => $conversation->id,
                    'actor_id' => $actor->getKey(),
                    'event_type' => 'reopened',
                    'old_value' => ['status' => ChatConversation::STATUS_RESOLVED],
                    'new_value' => ['status' => ChatConversation::STATUS_WAITING_AGENT],
                ]);

                $conversation = $conversation->fresh();
                $resolutionAction = 'reopened';
                $enteredActive = true;
            }

            if ($order
                && ! $actor->canViewAllAdminData()
                && (int) $conversation->assigned_to_id !== (int) $actor->getKey()) {
                $previousAssigneeId = $conversation->assigned_to_id
                    ? (int) $conversation->assigned_to_id
                    : $previousAssigneeId;
                $this->changeAssignee($conversation, $actor, (int) $actor->getKey());
                $conversation = $conversation->fresh();
            }

            if ($resolutionAction === null) {
                return [$conversation, false, null, $previousAssigneeId, false];
            }

            if (! $conversation->assigned_to_id) {
                $this->changeAssignee($conversation, $actor, (int) $actor->getKey());
            }

            ChatParticipant::query()->updateOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $actor->getKey()],
                ['role' => ChatParticipant::ROLE_AGENT, 'joined_at' => now(), 'left_at' => null],
            );

            ChatConversationEvent::query()->create([
                'conversation_id' => $conversation->id,
                'actor_id' => $actor->getKey(),
                'event_type' => 'agent_initiated',
                'new_value' => [
                    'action' => $resolutionAction,
                    'customer_id' => (int) $customer->getKey(),
                    'subject_type' => $type,
                    'subject_id' => $subjectId,
                    'initiated_from' => $type ? 'service_order' : 'admin',
                    'source_app' => $attributes['source_app'] ?? null,
                    'source_url' => $attributes['source_url'] ?? null,
                ],
            ]);

            $message = $conversation->messages()->create([
                'sender_id' => null,
                'sender_kind' => ChatMessage::SENDER_SYSTEM,
                'type' => ChatMessage::TYPE_SYSTEM,
                'body' => $this->outboundSystemMessage($actor, $order, $resolutionAction),
                'metadata' => [
                    'event' => 'agent_initiated',
                    'actor_id' => (int) $actor->getKey(),
                    'actor_username' => $actor->username,
                    'actor_display_name' => $actor->chatDisplayName(),
                    'action' => $resolutionAction,
                    'subject_type' => $type,
                    'subject_id' => $subjectId,
                    'initiated_from' => $type ? 'service_order' : 'admin',
                ],
                'is_internal' => false,
            ]);

            ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $actor->getKey())
                ->update([
                    'last_read_message_id' => $message->id,
                    'last_read_at' => now(),
                ]);

            $conversation->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
                'status' => ChatConversation::STATUS_WAITING_CUSTOMER,
                'resolved_by' => null,
                'resolved_at' => null,
            ]);

            return [$conversation->fresh(), $created, $message, $previousAssigneeId, $enteredActive];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: ChatMessage, 1: ChatConversation, 2: bool}
     */
    public function send(ChatConversation $conversation, User $sender, array $attributes): array
    {
        return DB::transaction(function () use ($conversation, $sender, $attributes): array {
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
            $sender = User::query()->findOrFail($sender->getKey());
            Gate::forUser($sender)->authorize('send', $locked);

            if (! empty($attributes['client_message_id'])) {
                $existing = $locked->messages()
                    ->withTrashed()
                    ->where('client_message_id', $attributes['client_message_id'])
                    ->first();

                if ($existing) {
                    if ((int) $existing->sender_id !== (int) $sender->getKey()) {
                        throw ValidationException::withMessages([
                            'client_message_id' => 'Mã tin nhắn đã được sử dụng.',
                        ]);
                    }

                    return [$existing, $locked, false];
                }
            }

            if ($locked->status === ChatConversation::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'conversation' => 'Cuộc trò chuyện đã đóng.',
                ]);
            }

            $isCustomer = (int) $locked->customer_id === (int) $sender->getKey();
            $internal = (bool) ($attributes['is_internal'] ?? false);

            if ($internal && ($isCustomer || ! $sender->can('chats.manage'))) {
                throw new AuthorizationException('Bạn không có quyền gửi ghi chú nội bộ.');
            }

            if (isset($attributes['reply_to_id'])) {
                $replyExists = $locked->messages()
                    ->whereKey((int) $attributes['reply_to_id'])
                    ->when($isCustomer, fn ($query) => $query->where('is_internal', false))
                    ->exists();

                if (! $replyExists) {
                    throw ValidationException::withMessages([
                        'reply_to_id' => 'Tin nhắn được trả lời không thuộc cuộc trò chuyện này.',
                    ]);
                }
            }

            if (! $isCustomer && ! $locked->assigned_to_id) {
                $this->changeAssignee($locked, $sender, (int) $sender->getKey());
            }

            $message = $locked->messages()->create([
                'sender_id' => $sender->getKey(),
                'sender_kind' => $isCustomer ? ChatMessage::SENDER_CUSTOMER : ChatMessage::SENDER_AGENT,
                'type' => $internal
                    ? ChatMessage::TYPE_INTERNAL_NOTE
                    : (! empty($attributes['images']) ? ChatMessage::TYPE_IMAGE : ChatMessage::TYPE_TEXT),
                'body' => trim((string) ($attributes['body'] ?? '')) ?: null,
                'reply_to_id' => $attributes['reply_to_id'] ?? null,
                'client_message_id' => $attributes['client_message_id'] ?? null,
                'is_internal' => $internal,
            ]);

            $participant = ChatParticipant::query()->updateOrCreate(
                ['conversation_id' => $locked->id, 'user_id' => $sender->getKey()],
                [
                    'role' => $isCustomer ? ChatParticipant::ROLE_CUSTOMER : ChatParticipant::ROLE_AGENT,
                    'joined_at' => now(),
                    'left_at' => null,
                ],
            );
            $participant->update([
                'last_read_message_id' => $message->id,
                'last_read_at' => now(),
            ]);

            if (! $internal) {
                $locked->last_message_id = $message->id;
                $locked->last_message_at = $message->created_at;
                $locked->status = $isCustomer
                    ? ChatConversation::STATUS_WAITING_AGENT
                    : ChatConversation::STATUS_WAITING_CUSTOMER;
                $locked->resolved_by = null;
                $locked->resolved_at = null;
            }

            $locked->save();

            return [$message, $locked->fresh(), true];
        });
    }

    /** @return array{0: ChatParticipant|null, 1: bool} */
    public function markRead(ChatConversation $conversation, User $reader, ?int $requestedMessageId = null): array
    {
        return DB::transaction(function () use ($conversation, $reader, $requestedMessageId): array {
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
            $reader = User::query()->findOrFail($reader->getKey());
            Gate::forUser($reader)->authorize('view', $locked);
            $isCustomer = (int) $locked->customer_id === (int) $reader->getKey();

            $messageQuery = $locked->messages()->when(
                $isCustomer,
                fn ($query) => $query->where('is_internal', false),
            );
            $message = $requestedMessageId
                ? (clone $messageQuery)->whereKey($requestedMessageId)->first()
                : (clone $messageQuery)->latest('id')->first();

            if ($requestedMessageId && ! $message) {
                throw ValidationException::withMessages([
                    'last_read_message_id' => 'Tin nhắn không thuộc cuộc trò chuyện này.',
                ]);
            }

            if (! $message) {
                return [null, false];
            }

            $participant = ChatParticipant::query()->firstOrNew([
                'conversation_id' => $locked->id,
                'user_id' => $reader->getKey(),
            ]);
            $participant->role = $isCustomer ? ChatParticipant::ROLE_CUSTOMER : ChatParticipant::ROLE_AGENT;
            $participant->joined_at ??= now();
            $participant->left_at = null;

            $advanced = ! $participant->last_read_message_id
                || (int) $message->id > (int) $participant->last_read_message_id;

            if ($advanced) {
                $participant->last_read_message_id = $message->id;
                $participant->last_read_at = now();
            }

            $participant->save();

            return [$participant->fresh('user'), $advanced];
        });
    }

    public function assign(
        ChatConversation $conversation,
        User $actor,
        ?User $assignee,
        bool $authorizeActor = true,
    ): ChatConversation {
        return DB::transaction(function () use ($conversation, $actor, $assignee, $authorizeActor): ChatConversation {
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
            $actor = User::query()->findOrFail($actor->getKey());
            if ($authorizeActor) {
                Gate::forUser($actor)->authorize('assign', $locked);
            }

            if ($assignee) {
                $assignee = User::query()->find($assignee->getKey());

                if (! $assignee || ! $this->canBeAssignee($assignee, (int) $locked->customer_id)) {
                    throw ValidationException::withMessages([
                        'assigned_to_id' => 'Người được chọn không có quyền trả lời chat.',
                    ]);
                }
            }

            $this->changeAssignee($locked, $actor, $assignee?->getKey());

            return $locked->fresh();
        });
    }

    public function updateStatus(ChatConversation $conversation, User $actor, string $status): ChatConversation
    {
        return DB::transaction(function () use ($conversation, $actor, $status): ChatConversation {
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
            $actor = User::query()->findOrFail($actor->getKey());
            Gate::forUser($actor)->authorize('manage', $locked);
            $oldStatus = $locked->status;
            $values = ['status' => $status];

            if (in_array($status, [ChatConversation::STATUS_RESOLVED, ChatConversation::STATUS_CLOSED], true)) {
                $values['resolved_by'] = $actor->getKey();
                $values['resolved_at'] = now();
            } else {
                $values['resolved_by'] = null;
                $values['resolved_at'] = null;
            }

            $locked->update($values);

            ChatConversationEvent::query()->create([
                'conversation_id' => $locked->id,
                'actor_id' => $actor->getKey(),
                'event_type' => 'status_changed',
                'old_value' => ['status' => $oldStatus],
                'new_value' => ['status' => $status],
            ]);

            return $locked->fresh();
        });
    }

    private function authorizeOutboundActor(User $actor): void
    {
        $hasRequiredChatPermissions = $actor->hasRole('super-admin')
            || ($actor->can(Permission::ChatsView->value)
                && $actor->can(Permission::ChatsReply->value));

        if ($actor->isLocked()
            || $actor->status !== User::STATUS_ACTIVE
            || ! $actor->isChatAgent()
            || ! $hasRequiredChatPermissions) {
            throw new AuthorizationException('Bạn không có quyền chủ động tạo cuộc trò chuyện.');
        }
    }

    private function authorizeOutboundServiceOrder(User $actor, ServiceOrder $order): void
    {
        if ($actor->canViewAllAdminData()) {
            return;
        }

        $canViewServiceOrders = $actor->can(Permission::ServiceOrdersView->value)
            || $actor->can(Permission::ServiceOrdersProcess->value);

        if (! $canViewServiceOrders
            || (int) $order->receiver_id !== (int) $actor->getKey()
            || $order->status !== 'approved') {
            throw new AuthorizationException('Bạn chỉ có thể chủ động chat với đơn dịch vụ đang nhận.');
        }
    }

    private function outboundSystemMessage(
        User $actor,
        ?ServiceOrder $order,
        string $resolutionAction,
    ): string {
        $verb = $resolutionAction === 'reopened' ? 'đã mở lại' : 'đã bắt đầu';

        if ($order) {
            return "{$actor->chatDisplayName()} {$verb} hỗ trợ đơn dịch vụ #{$order->getKey()}.";
        }

        return "{$actor->chatDisplayName()} {$verb} cuộc trò chuyện hỗ trợ.";
    }

    private function changeAssignee(ChatConversation $conversation, User $actor, ?int $newAssigneeId): bool
    {
        $oldAssigneeId = $conversation->assigned_to_id;

        if ((int) $oldAssigneeId === (int) $newAssigneeId) {
            return false;
        }

        if ($oldAssigneeId) {
            ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $oldAssigneeId)
                ->where('role', ChatParticipant::ROLE_AGENT)
                ->update(['left_at' => now()]);
        }

        if ($newAssigneeId) {
            ChatParticipant::query()->updateOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $newAssigneeId],
                ['role' => ChatParticipant::ROLE_AGENT, 'joined_at' => now(), 'left_at' => null],
            );
        }

        $conversation->update(['assigned_to_id' => $newAssigneeId]);

        ChatConversationEvent::query()->create([
            'conversation_id' => $conversation->id,
            'actor_id' => $actor->getKey(),
            'event_type' => $newAssigneeId ? 'assigned' : 'unassigned',
            'old_value' => ['assigned_to_id' => $oldAssigneeId],
            'new_value' => ['assigned_to_id' => $newAssigneeId],
        ]);

        return true;
    }

    private function eligibleAssigneeId(?int $suggestedAssigneeId, User $customer): ?int
    {
        if (! $suggestedAssigneeId || (int) $suggestedAssigneeId === (int) $customer->getKey()) {
            return null;
        }

        $assignee = User::query()->find($suggestedAssigneeId);

        if (! $assignee || ! $this->canBeAssignee($assignee, (int) $customer->getKey())) {
            return null;
        }

        return (int) $assignee->getKey();
    }

    private function canBeAssignee(User $assignee, int $customerId): bool
    {
        return (int) $assignee->getKey() !== $customerId
            && $assignee->status === User::STATUS_ACTIVE
            && $assignee->isChatAgent()
            && $assignee->can('chats.view')
            && $assignee->can('chats.reply');
    }
}
