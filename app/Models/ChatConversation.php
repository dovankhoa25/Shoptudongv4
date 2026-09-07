<?php

namespace App\Models;

use App\Scopes\ReceiverOwnedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChatConversation extends Model
{
    public const CATEGORY_GENERAL = 'general';

    public const CATEGORY_ORDER_SUPPORT = 'order_support';

    public const CATEGORY_PAYMENT_SUPPORT = 'payment_support';

    public const STATUS_WAITING_AGENT = 'waiting_agent';

    public const STATUS_WAITING_CUSTOMER = 'waiting_customer';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'customer_id',
        'category',
        'subject_type',
        'subject_id',
        'assigned_to_id',
        'status',
        'priority',
        'source_app',
        'source_url',
        'last_message_id',
        'last_message_at',
        'resolved_by',
        'resolved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()
            ->morphWith([
                ServiceOrder::class => ['service:id,name'],
                NickOrder::class => ['nick.category:id,name'],
                GoldTransaction::class => ['server:id,name,name_view'],
                GemTransaction::class => ['server:id,name,name_view'],
            ])
            ->constrain([
                ServiceOrder::class => fn (Builder $query) => $query->withoutGlobalScope(ReceiverOwnedScope::class),
            ]);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id')->withTrashed();
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatConversationEvent::class, 'conversation_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canViewAllChats() && $user->can('chats.view')) {
            return $query;
        }

        if (! $user->isChatAgent() || ! $user->can('chats.view')) {
            return $query->where($query->qualifyColumn('customer_id'), $user->getKey());
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible
                ->where('customer_id', $user->getKey())
                ->orWhere('assigned_to_id', $user->getKey())
                ->orWhereHas('participants', fn (Builder $participants) => $participants
                    ->where('user_id', $user->getKey())
                    ->where('role', ChatParticipant::ROLE_AGENT)
                    ->whereNull('left_at'))
                ->orWhere(function (Builder $serviceOrders) use ($user): void {
                    $serviceOrders
                        ->whereNull('assigned_to_id')
                        ->where('subject_type', 'service_order')
                        ->whereIn('subject_id', ServiceOrder::withoutReceiverOwnedScope()
                            ->select('id')
                            ->where('receiver_id', $user->getKey()));
                })
                ->orWhere(function (Builder $nickOrders) use ($user): void {
                    $nickOrders
                        ->whereNull('assigned_to_id')
                        ->where('subject_type', 'nick_order')
                        ->whereIn('subject_id', NickOrder::query()
                            ->select('id')
                            ->where('seller_id', $user->getKey()));
                });
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ((int) $this->customer_id === (int) $user->getKey()) {
            return true;
        }

        if ($user->canViewAllChats() && $user->can('chats.view')) {
            return true;
        }

        if (! $user->isChatAgent() || ! $user->can('chats.view')) {
            return false;
        }

        if ((int) $this->assigned_to_id === (int) $user->getKey()) {
            return true;
        }

        if ($this->relationLoaded('participants') && $this->participants->contains(
            fn (ChatParticipant $participant): bool => (int) $participant->user_id === (int) $user->getKey()
                && $participant->role === ChatParticipant::ROLE_AGENT
                && $participant->left_at === null,
        )) {
            return true;
        }

        if ($this->assigned_to_id === null && $this->relationLoaded('subject')) {
            return match ($this->subject_type) {
                'service_order' => (int) $this->subject?->getAttribute('receiver_id') === (int) $user->getKey(),
                'nick_order' => (int) $this->subject?->getAttribute('seller_id') === (int) $user->getKey(),
                default => false,
            };
        }

        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    public function unreadCountFor(User $user): int
    {
        $lastReadId = $this->participants()
            ->where('user_id', $user->getKey())
            ->value('last_read_message_id') ?? 0;

        return $this->messages()
            ->where('id', '>', $lastReadId)
            ->where(fn (Builder $query) => $query
                ->whereNull('sender_id')
                ->orWhere('sender_id', '!=', $user->getKey()))
            ->when(
                (int) $this->customer_id === (int) $user->getKey(),
                fn (Builder $query) => $query->where('is_internal', false),
            )
            ->count();
    }

    public function scopeWithUnreadCountFor(Builder $query, User $user): Builder
    {
        $userId = (int) $user->getKey();

        return $query
            ->withCount([
                'messages as unread_count' => fn (Builder $messages) => $messages
                    ->where(fn (Builder $sender) => $sender
                        ->whereNull('chat_messages.sender_id')
                        ->orWhere('chat_messages.sender_id', '!=', $userId))
                    ->whereRaw(
                        'chat_messages.id > COALESCE((SELECT chat_participants.last_read_message_id FROM chat_participants WHERE chat_participants.conversation_id = chat_messages.conversation_id AND chat_participants.user_id = ? LIMIT 1), 0)',
                        [$userId],
                    )
                    ->where(fn (Builder $visibility) => $visibility
                        ->where('chat_messages.is_internal', false)
                        ->orWhereRaw('chat_conversations.customer_id != ?', [$userId])),
            ])
            ->withMax([
                'messages as latest_visible_message_id' => fn (Builder $messages) => $messages
                    ->where(fn (Builder $visibility) => $visibility
                        ->where('chat_messages.is_internal', false)
                        ->orWhereRaw('chat_conversations.customer_id != ?', [$userId])),
            ], 'id');
    }
}
