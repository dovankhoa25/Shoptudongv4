<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ChatMessage extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes;

    public const MEDIA_COLLECTION_IMAGES = 'chat_images';

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_AGENT = 'agent';

    public const SENDER_SYSTEM = 'system';

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_TIP = 'tip';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_INTERNAL_NOTE = 'internal_note';

    public const EVENT_WELCOME_MESSAGE = 'welcome_message';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_kind',
        'type',
        'body',
        'reply_to_id',
        'client_message_id',
        'metadata',
        'is_internal',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatMessageReaction::class, 'message_id');
    }

    public function tip(): HasOne
    {
        return $this->hasOne(ChatTip::class, 'message_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION_IMAGES)
            ->useDisk((string) config('chat.attachments.disk', 'chat'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->onlyKeepLatest(max(1, (int) config('chat.attachments.max_files', 4)));
    }

    public function scopeWithoutAutomatedWelcome(Builder $query): Builder
    {
        $metadataEvent = $query->qualifyColumn('metadata').'->event';

        return $query->where(function (Builder $messages) use ($metadataEvent): void {
            $messages
                ->whereNull($metadataEvent)
                ->orWhere($metadataEvent, '!=', self::EVENT_WELCOME_MESSAGE);
        });
    }

    /**
     * @return list<array{emoji: string, count: int, user_ids: list<int>, reacted_by_me?: bool}>
     */
    public function reactionSummary(?int $viewerId = null): array
    {
        $reactions = $this->relationLoaded('reactions')
            ? $this->reactions
            : $this->reactions()->get();
        $allowedOrder = collect(config('chat.reactions.allowed', []))->flip();

        return $reactions
            ->groupBy('emoji')
            ->map(function ($items, string $emoji) use ($viewerId): array {
                $userIds = $items->pluck('user_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $summary = [
                    'emoji' => $emoji,
                    'count' => count($userIds),
                    'user_ids' => $userIds,
                ];

                if ($viewerId !== null) {
                    $summary['reacted_by_me'] = in_array($viewerId, $userIds, true);
                }

                return $summary;
            })
            ->sortBy(fn (array $reaction) => $allowedOrder->get($reaction['emoji'], PHP_INT_MAX))
            ->values()
            ->all();
    }
}
