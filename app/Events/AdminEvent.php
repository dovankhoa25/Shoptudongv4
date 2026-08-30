<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class AdminEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly string $resource,
        public readonly int|string $resourceId,
        public readonly string $action,
        public readonly ?string $status,
        public readonly string $message,
        public readonly string $occurredAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('Admin.realtime');
    }

    /** @return array<string, int|string|null> */
    public function broadcastWith(): array
    {
        return [
            'resource' => $this->resource,
            'resource_id' => $this->resourceId,
            'action' => $this->action,
            'status' => $this->status,
            'message' => $this->message,
            'occurred_at' => $this->occurredAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'AdminEvent';
    }
}
