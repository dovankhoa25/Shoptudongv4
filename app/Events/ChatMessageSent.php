<?php

namespace App\Events;

use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $message
     * @param  list<int>  $recipientIds
     * @param  array<string, mixed>  $conversation
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $message,
        public readonly array $recipientIds,
        public readonly array $conversation = [],
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
            'message' => $this->message,
            'conversation' => $this->conversation,
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }
}
