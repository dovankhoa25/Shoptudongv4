<?php

namespace App\Http\Controllers;

use App\Http\Resources\Chat\ChatConversationResource;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\GemTransaction;
use App\Models\GoldTransaction;
use App\Models\NickOrder;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Chat\ChatManager;
use App\Services\Chat\ChatRealtimeNotifier;
use App\Services\Chat\ChatSubjectResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatManager $manager,
        private readonly ChatSubjectResolver $subjects,
        private readonly ChatRealtimeNotifier $realtime,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(
            $user->isLocked()
                || ($user->isChatAgent() && $user->status !== User::STATUS_ACTIVE),
            403,
        );

        $validated = $request->validate([
            'view' => ['nullable', Rule::in(['active', 'completed', 'all'])],
            'status' => ['nullable', Rule::in([
                ChatConversation::STATUS_WAITING_AGENT,
                ChatConversation::STATUS_WAITING_CUSTOMER,
                ChatConversation::STATUS_RESOLVED,
                ChatConversation::STATUS_CLOSED,
            ])],
            'period' => ['nullable', Rule::in(['7d', '30d', '90d', 'all'])],
            'assignment' => ['nullable', Rule::in(['mine', 'unassigned'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // Keep the public/customer inbox backward compatible. Only the dedicated
        // admin inbox defaults to active work; callers may always request `all`.
        $view = $validated['view'] ?? ($request->routeIs('admin.chat.conversations.index') ? 'active' : 'all');
        $period = $validated['period'] ?? 'all';

        $baseQuery = ChatConversation::query()
            ->visibleTo($user)
            ->when(
                ($validated['assignment'] ?? null) === 'mine' && $user->canViewAllAdminData(),
                fn (Builder $query) => $query->where('assigned_to_id', $user->getKey()),
            )
            ->when(($validated['assignment'] ?? null) === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_to_id'))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->when(is_numeric($search), fn (Builder $byId) => $byId->orWhereKey((int) $search))
                        ->orWhereHas('customer', fn (Builder $customer) => $customer
                            ->where('username', 'like', '%'.$search.'%'));
                });
            });

        $statusCounts = $this->conversationCountsByStatus($baseQuery);
        $unreadCounts = $this->unreadCountsByStatus($baseQuery, $user);

        $query = (clone $baseQuery)
            ->when($view === 'active', fn (Builder $query) => $query->whereIn('status', [
                ChatConversation::STATUS_WAITING_AGENT,
                ChatConversation::STATUS_WAITING_CUSTOMER,
            ]))
            ->when($view === 'completed', fn (Builder $query) => $query->whereIn('status', [
                ChatConversation::STATUS_RESOLVED,
                ChatConversation::STATUS_CLOSED,
            ]))
            ->when(
                $view === 'completed' && $period !== 'all',
                fn (Builder $query) => $query->whereRaw(
                    'COALESCE(chat_conversations.resolved_at, chat_conversations.updated_at) >= ?',
                    [now()->subDays((int) rtrim($period, 'd'))],
                ),
            )
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));

        $unreadTotal = $this->unreadTotal($query, $user);
        $query
            ->with($this->conversationRelations())
            ->withUnreadCountFor($user)
            ->when($view === 'active', fn (Builder $query) => $query
                ->orderByRaw(
                    'CASE chat_conversations.status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                    [ChatConversation::STATUS_WAITING_AGENT, ChatConversation::STATUS_WAITING_CUSTOMER],
                )
                ->orderByDesc('unread_count'))
            ->when($view === 'completed', fn (Builder $query) => $query
                ->orderByRaw('COALESCE(chat_conversations.resolved_at, chat_conversations.updated_at) DESC'))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 30));

        return ChatConversationResource::collection($paginator)
            ->additional([
                'view' => $view,
                'period' => $period,
                'counts' => $this->groupedConversationCounts($statusCounts),
                'unread_counts' => $this->groupedConversationCounts($unreadCounts),
                'unread_total' => $unreadTotal,
            ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::in([
                ChatConversation::CATEGORY_GENERAL,
                ChatConversation::CATEGORY_ORDER_SUPPORT,
                ChatConversation::CATEGORY_PAYMENT_SUPPORT,
            ])],
            'subject_type' => ['nullable', Rule::in(ChatSubjectResolver::TYPES)],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'source_app' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if (isset($validated['subject_type']) !== isset($validated['subject_id'])) {
            throw ValidationException::withMessages([
                'subject_id' => 'Loại đơn và mã đơn phải được gửi cùng nhau.',
            ]);
        }

        [$conversation, $created, $previousAssigneeId] = $this->manager->resolve($request->user(), $validated);
        $this->prepareConversation($conversation, $request->user());

        $this->realtime->inbox(
            $created ? 'created' : 'updated',
            $conversation,
            $this->inboxPayload($conversation),
            $previousAssigneeId ? [(int) $previousAssigneeId] : [],
        );

        return response()->json([
            'data' => (new ChatConversationResource($conversation))->resolve($request),
        ], $created ? 201 : 200);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $this->prepareConversation($conversation, $request->user());

        $messages = $conversation->messages()
            ->with('sender:id,username,avatar')
            ->when(
                (int) $conversation->customer_id === (int) $request->user()->getKey(),
                fn (Builder $query) => $query->where('is_internal', false),
            )
            ->latest('id')
            ->limit(61)
            ->get();
        $hasMore = $messages->count() > 60;
        $messages = $messages->take(60)->reverse()->values();

        $messages->each(fn (ChatMessage $message) => $message->setRelation('conversation', $conversation));

        return response()->json([
            'data' => (new ChatConversationResource($conversation))->resolve($request),
            'messages' => ChatMessageResource::collection($messages)->resolve($request),
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $messages->first()?->id : null,
        ]);
    }

    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $validated = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            // 0 là checkpoint hợp lệ cho hội thoại chưa từng có tin nhắn.
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($validated['before'], $validated['after_id'])) {
            throw ValidationException::withMessages([
                'after_id' => 'Không thể dùng after_id cùng với before.',
            ]);
        }

        $limit = (int) ($validated['limit'] ?? 60);
        $isDeltaRequest = isset($validated['after_id']);
        $this->prepareConversation($conversation, $request->user());

        $query = $conversation->messages()
            ->with('sender:id,username,avatar')
            ->when($validated['before'] ?? null, fn (Builder $query, int $before) => $query->where('id', '<', $before))
            ->when($validated['after_id'] ?? null, fn (Builder $query, int $afterId) => $query->where('id', '>', $afterId))
            ->when(
                (int) $conversation->customer_id === (int) $request->user()->getKey(),
                fn (Builder $query) => $query->where('is_internal', false),
            );

        $query->orderBy('id', $isDeltaRequest ? 'asc' : 'desc');

        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit);
        if (! $isDeltaRequest) {
            $messages = $messages->reverse();
        }
        $messages = $messages->values();
        $messages->each(fn (ChatMessage $message) => $message->setRelation('conversation', $conversation));

        return response()->json([
            'data' => ChatMessageResource::collection($messages)->resolve($request),
            'has_more' => $hasMore,
            'next_before' => ! $isDeltaRequest && $hasMore ? $messages->first()?->id : null,
            'next_after' => $isDeltaRequest && $hasMore ? $messages->last()?->id : null,
        ]);
    }

    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('send', $conversation);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer', 'min:1'],
            'client_message_id' => ['nullable', 'uuid'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        $isCustomer = (int) $conversation->customer_id === (int) $request->user()->getKey();
        if ($isCustomer && ($validated['is_internal'] ?? false)) {
            abort(403, 'Khách hàng không thể gửi ghi chú nội bộ.');
        }
        if (! $isCustomer && ($validated['is_internal'] ?? false) && ! $request->user()->can('chats.manage')) {
            abort(403, 'Bạn không có quyền gửi ghi chú nội bộ.');
        }

        [$message, $conversation, $created] = $this->manager->send($conversation, $request->user(), $validated);
        $this->prepareConversation($conversation, $request->user());
        $message->loadMissing('sender:id,username,avatar');
        $message->setRelation('conversation', $conversation);
        $messagePayload = (new ChatMessageResource($message))->resolve($request);

        if ($created) {
            $realtimeMessagePayload = $messagePayload;
            unset($realtimeMessagePayload['is_mine']);

            $this->realtime->message(
                $conversation,
                $realtimeMessagePayload,
                (bool) $message->is_internal,
                $this->inboxPayload($conversation),
            );

            $senderParticipant = $conversation->participants
                ->firstWhere('user_id', $request->user()->getKey());
            if ($senderParticipant?->last_read_message_id) {
                $this->realtime->read(
                    $conversation,
                    [
                        'id' => (int) $request->user()->id,
                        'username' => $request->user()->username,
                        'avatar' => $request->user()->avatar_url,
                        'kind' => $senderParticipant->role,
                    ],
                    (int) $senderParticipant->last_read_message_id,
                    $senderParticipant->last_read_at->toIso8601String(),
                );
            }
        }

        return response()->json([
            'data' => $messagePayload,
            'conversation' => (new ChatConversationResource($conversation))->resolve($request),
        ], $created ? 201 : 200);
    }

    public function read(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $validated = $request->validate([
            'last_read_message_id' => ['nullable', 'integer', 'min:1'],
        ]);
        [$participant, $advanced] = $this->manager->markRead(
            $conversation,
            $request->user(),
            isset($validated['last_read_message_id']) ? (int) $validated['last_read_message_id'] : null,
        );

        if ($advanced && $participant?->last_read_message_id) {
            $this->realtime->read(
                $conversation,
                [
                    'id' => (int) $request->user()->id,
                    'username' => $request->user()->username,
                    'avatar' => $request->user()->avatar_url,
                    'kind' => $participant->role,
                ],
                (int) $participant->last_read_message_id,
                $participant->last_read_at->toIso8601String(),
            );
        }

        return response()->json([
            'data' => [
                'last_read_message_id' => $participant?->last_read_message_id,
                'last_read_at' => $participant?->last_read_at?->toIso8601String(),
            ],
        ]);
    }

    public function assign(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('assign', $conversation);
        $validated = $request->validate([
            'assigned_to_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $assignee = isset($validated['assigned_to_id'])
            ? User::query()->findOrFail((int) $validated['assigned_to_id'])
            : null;

        if ($assignee && ((int) $assignee->id === (int) $conversation->customer_id
            || ! $assignee->isChatAgent()
            || ! $assignee->can('chats.view')
            || ! $assignee->can('chats.reply')
            || $assignee->status !== User::STATUS_ACTIVE)) {
            throw ValidationException::withMessages([
                'assigned_to_id' => 'Người được chọn không có quyền trả lời chat.',
            ]);
        }

        $previousAssigneeId = $conversation->assigned_to_id ? (int) $conversation->assigned_to_id : null;
        $conversation = $this->manager->assign($conversation, $request->user(), $assignee);
        $this->prepareConversation($conversation, $request->user());
        $this->realtime->inbox(
            'assigned',
            $conversation,
            $this->inboxPayload($conversation),
            $previousAssigneeId ? [$previousAssigneeId] : [],
        );

        return response()->json([
            'data' => (new ChatConversationResource($conversation))->resolve($request),
        ]);
    }

    public function updateStatus(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('manage', $conversation);
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                ChatConversation::STATUS_WAITING_AGENT,
                ChatConversation::STATUS_WAITING_CUSTOMER,
                ChatConversation::STATUS_RESOLVED,
                ChatConversation::STATUS_CLOSED,
            ])],
        ]);

        $conversation = $this->manager->updateStatus($conversation, $request->user(), $validated['status']);
        $this->prepareConversation($conversation, $request->user());
        $this->realtime->inbox(
            'status',
            $conversation,
            $this->inboxPayload($conversation),
        );

        return response()->json([
            'data' => (new ChatConversationResource($conversation))->resolve($request),
        ]);
    }

    public function contexts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subjects->listFor($request->user())]);
    }

    public function subject(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        abort_unless($conversation->subject_type && $conversation->subject_id, 404);

        return response()->json([
            'data' => $this->subjectDetail($conversation),
        ]);
    }

    public function agents(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->status === User::STATUS_ACTIVE
                && $request->user()->canViewAllAdminData()
                && $request->user()->can('chats.view')
                && $request->user()->can('chats.assign'),
            403,
        );

        $agents = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['admin', 'super-admin', 'ctv']))
            ->with('roles:id,name')
            ->orderBy('username')
            ->get(['id', 'username', 'avatar'])
            ->filter(fn (User $user) => $user->can('chats.view') && $user->can('chats.reply'))
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'avatar' => $user->avatar_url,
                'roles' => $user->getRoleNames()->values(),
            ])
            ->values();

        return response()->json(['data' => $agents]);
    }

    /** @return list<string> */
    private function conversationRelations(): array
    {
        return [
            'customer:id,username,avatar',
            'assignee:id,username,avatar',
            'participants.user:id,username,avatar',
            'lastMessage.sender:id,username,avatar',
            'subject',
        ];
    }

    private function prepareConversation(ChatConversation $conversation, User $viewer): void
    {
        $conversation->load($this->conversationRelations());
        $conversation->setAttribute('unread_count', $conversation->unreadCountFor($viewer));
    }

    /** @return array<string, mixed> */
    private function inboxPayload(ChatConversation $conversation): array
    {
        return [
            'id' => (int) $conversation->id,
            'customer_id' => (int) $conversation->customer_id,
            'assigned_to_id' => $conversation->assigned_to_id ? (int) $conversation->assigned_to_id : null,
            'assignee' => $conversation->assignee ? [
                'id' => (int) $conversation->assignee->id,
                'username' => $conversation->assignee->username,
                'avatar' => $conversation->assignee->avatar_url,
            ] : null,
            'category' => $conversation->category,
            'subject_type' => $conversation->subject_type,
            'subject_id' => $conversation->subject_id ? (int) $conversation->subject_id : null,
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'resolved_at' => $conversation->resolved_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function subjectDetail(ChatConversation $conversation): array
    {
        $type = $conversation->subject_type;
        $subject = match ($type) {
            'service_order' => ServiceOrder::withoutReceiverOwnedScope()
                ->with(['user:id,username', 'receiver:id,username', 'service:id,name,processing_time,warranty'])
                ->where('user_id', $conversation->customer_id)
                ->findOrFail($conversation->subject_id),
            'nick_order' => NickOrder::query()
                ->with(['buyer:id,username', 'seller:id,username', 'nick.category:id,name'])
                ->where('buyer_id', $conversation->customer_id)
                ->findOrFail($conversation->subject_id),
            'gold_transaction' => GoldTransaction::query()
                ->with(['user:id,username', 'server:id,name,name_view', 'bot:id,name'])
                ->where('type', GoldTransaction::TYPE_ORDER)
                ->where('user_id', $conversation->customer_id)
                ->findOrFail($conversation->subject_id),
            'gem_transaction' => GemTransaction::query()
                ->with(['user:id,username', 'server:id,name,name_view'])
                ->where('user_id', $conversation->customer_id)
                ->findOrFail($conversation->subject_id),
            default => abort(404),
        };
        $summary = $this->subjects->summary($type, $subject, (int) $conversation->subject_id);

        $fields = match ($type) {
            'service_order' => [
                ['label' => 'Khách hàng', 'value' => $subject->user?->username],
                ['label' => 'Dịch vụ', 'value' => $subject->service?->name],
                ['label' => 'Tài khoản', 'value' => $subject->account],
                ['label' => 'Giá dịch vụ', 'value' => number_format((float) $subject->service_price, 0, ',', '.').' đ'],
                ['label' => 'Người nhận', 'value' => $subject->receiver?->username],
                ['label' => 'Mô tả', 'value' => $subject->description],
            ],
            'nick_order' => [
                ['label' => 'Người mua', 'value' => $subject->buyer?->username],
                ['label' => 'Người bán', 'value' => $subject->seller?->username],
                ['label' => 'Danh mục', 'value' => $subject->nick?->category?->name],
                ['label' => 'Tài khoản', 'value' => $subject->nick?->account_name],
                ['label' => 'Giá bán', 'value' => number_format((float) $subject->price, 0, ',', '.').' đ'],
            ],
            'gold_transaction' => [
                ['label' => 'Khách hàng', 'value' => $subject->user?->username],
                ['label' => 'Máy chủ', 'value' => $subject->server?->name_view ?? $subject->server?->name],
                ['label' => 'Nhân vật', 'value' => $subject->character_name],
                ['label' => 'Số vàng', 'value' => number_format((float) $subject->gold_qty, 0, ',', '.')],
                ['label' => 'Thành tiền', 'value' => number_format((float) $subject->amount_vnd, 0, ',', '.').' đ'],
                ['label' => 'Bot xử lý', 'value' => $subject->bot?->name],
                ['label' => 'Lý do hủy', 'value' => $subject->cancel_reason],
            ],
            'gem_transaction' => [
                ['label' => 'Khách hàng', 'value' => $subject->user?->username],
                ['label' => 'Máy chủ', 'value' => $subject->server?->name_view ?? $subject->server?->name],
                ['label' => 'Nhân vật', 'value' => $subject->character_name],
                ['label' => 'Số ngọc', 'value' => number_format((float) $subject->gem_qty, 0, ',', '.')],
                ['label' => 'Thành tiền', 'value' => number_format((float) $subject->amount_vnd, 0, ',', '.').' đ'],
            ],
            default => [],
        };

        return [
            'type' => $type,
            'id' => (int) $subject->getKey(),
            'label' => $summary['label'] ?? 'Đơn liên quan',
            'description' => $summary['description'] ?? null,
            'status' => $subject->getAttribute('status'),
            'fields' => array_values(array_filter(
                $fields,
                fn (array $field): bool => $field['value'] !== null && $field['value'] !== '',
            )),
            'created_at' => $subject->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $subject->getAttribute('updated_at')?->toIso8601String(),
        ];
    }

    private function unreadTotal(Builder $conversationQuery, User $user): int
    {
        $conversationIds = (clone $conversationQuery)
            ->reorder()
            ->select('chat_conversations.id');

        return ChatMessage::query()
            ->whereIn('chat_messages.conversation_id', $conversationIds)
            ->where(fn (Builder $messages) => $messages
                ->whereNull('chat_messages.sender_id')
                ->orWhere('chat_messages.sender_id', '!=', $user->getKey()))
            ->whereRaw(
                'chat_messages.id > COALESCE((SELECT chat_participants.last_read_message_id FROM chat_participants WHERE chat_participants.conversation_id = chat_messages.conversation_id AND chat_participants.user_id = ? LIMIT 1), 0)',
                [$user->getKey()],
            )
            ->where(fn (Builder $messages) => $messages
                ->where('chat_messages.is_internal', false)
                ->orWhereHas('conversation', fn (Builder $conversation) => $conversation
                    ->where('customer_id', '!=', $user->getKey())))
            ->count();
    }

    /** @return array<string, int> */
    private function conversationCountsByStatus(Builder $conversationQuery): array
    {
        $counts = (clone $conversationQuery)
            ->reorder()
            ->select('chat_conversations.status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('chat_conversations.status')
            ->pluck('aggregate', 'status');

        return collect($this->conversationStatuses())
            ->mapWithKeys(fn (string $status): array => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /** @return array<string, int> */
    private function unreadCountsByStatus(Builder $conversationQuery, User $user): array
    {
        $conversationIds = (clone $conversationQuery)
            ->reorder()
            ->select('chat_conversations.id');

        $counts = ChatMessage::query()
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_messages.conversation_id')
            ->whereIn('chat_messages.conversation_id', $conversationIds)
            ->where(fn (Builder $messages) => $messages
                ->whereNull('chat_messages.sender_id')
                ->orWhere('chat_messages.sender_id', '!=', $user->getKey()))
            ->whereRaw(
                'chat_messages.id > COALESCE((SELECT chat_participants.last_read_message_id FROM chat_participants WHERE chat_participants.conversation_id = chat_messages.conversation_id AND chat_participants.user_id = ? LIMIT 1), 0)',
                [$user->getKey()],
            )
            ->where(fn (Builder $messages) => $messages
                ->where('chat_messages.is_internal', false)
                ->orWhere('chat_conversations.customer_id', '!=', $user->getKey()))
            ->select('chat_conversations.status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('chat_conversations.status')
            ->pluck('aggregate', 'status');

        return collect($this->conversationStatuses())
            ->mapWithKeys(fn (string $status): array => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function groupedConversationCounts(array $counts): array
    {
        $active = $counts[ChatConversation::STATUS_WAITING_AGENT]
            + $counts[ChatConversation::STATUS_WAITING_CUSTOMER];
        $completed = $counts[ChatConversation::STATUS_RESOLVED]
            + $counts[ChatConversation::STATUS_CLOSED];

        return [
            'all' => $active + $completed,
            'active' => $active,
            'completed' => $completed,
            ChatConversation::STATUS_WAITING_AGENT => $counts[ChatConversation::STATUS_WAITING_AGENT],
            ChatConversation::STATUS_WAITING_CUSTOMER => $counts[ChatConversation::STATUS_WAITING_CUSTOMER],
            ChatConversation::STATUS_RESOLVED => $counts[ChatConversation::STATUS_RESOLVED],
            ChatConversation::STATUS_CLOSED => $counts[ChatConversation::STATUS_CLOSED],
        ];
    }

    /** @return list<string> */
    private function conversationStatuses(): array
    {
        return [
            ChatConversation::STATUS_WAITING_AGENT,
            ChatConversation::STATUS_WAITING_CUSTOMER,
            ChatConversation::STATUS_RESOLVED,
            ChatConversation::STATUS_CLOSED,
        ];
    }
}
