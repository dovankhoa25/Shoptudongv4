<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** A small invalidation signal. Never broadcast worker/account credentials. */
class NroShopUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $eventId,
        public readonly bool $catalog,
        public readonly array $buyerIds = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            ...($this->catalog ? [new Channel('Nro.Shop')] : []),
            new PrivateChannel('Nro.Admin'),
            ...array_map(fn (int $id) => new PrivateChannel("User.{$id}"), $this->buyerIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'NroShopUpdated';
    }

    public function broadcastWith(): array
    {
        return ['event_id' => $this->eventId, 'catalog' => $this->catalog];
    }
}
