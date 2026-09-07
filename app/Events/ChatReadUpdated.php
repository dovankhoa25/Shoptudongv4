<?php

namespace App\Events;

use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ChatReadUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /** @param array<string, mixed> $reader */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $reader,
        public readonly int $lastReadMessageId,
        public readonly string $readAt,
        public readonly array $recipientIds = [],
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
            'reader' => $this->reader,
            'last_read_message_id' => $this->lastReadMessageId,
            'read_at' => $this->readAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatReadUpdated';
    }
}
