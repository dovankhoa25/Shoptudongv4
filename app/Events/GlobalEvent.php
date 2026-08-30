<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class GlobalEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public string $type;
    public ?int $targetUserId;
    public array $payload;

    public function __construct(string $type, ?int $targetUserId = null, array $payload = [])
    {
        $this->type = $type;
        $this->targetUserId = $targetUserId;
        $this->payload = $payload;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('authenticated');
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'targetUserId' => $this->targetUserId,
            'payload' => $this->payload,
        ];
    }

    public function broadcastAs(): string
    {
        return 'GlobalEvent';
    }
}
