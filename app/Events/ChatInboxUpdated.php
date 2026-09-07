<?php

namespace App\Events;

use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ChatInboxUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $conversation
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public readonly string $action,
        public readonly array $conversation,
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
            'action' => $this->action,
            'conversation' => $this->conversation,
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatInboxUpdated';
    }
}
