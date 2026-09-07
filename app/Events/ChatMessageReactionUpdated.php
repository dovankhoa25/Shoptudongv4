<?php

namespace App\Events;

use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ChatMessageReactionUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{emoji: string, count: int, user_ids: list<int>}>  $reactions
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly array $reactions,
        public readonly int $actorId,
        public readonly bool $active,
        public readonly array $recipientIds,
    ) {}

    /** @return list<\Illuminate\Broadcasting\PrivateChannel> */
    public function broadcastOn(): array
    {
        return app(ChatRealtimeChannel::class)->privateChannelsFor($this->recipientIds);
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'reactions' => $this->reactions,
            'actor_id' => $this->actorId,
            'active' => $this->active,
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageReactionUpdated';
    }
}
