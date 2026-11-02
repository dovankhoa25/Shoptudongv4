<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Public invalidation only; stock is read from the existing overview endpoint. */
class TradingBotOverviewUpdated implements ShouldBroadcastNow
{
    public function broadcastOn(): array { return [new Channel('Trading.Bots')]; }
    public function broadcastAs(): string { return 'TradingBotOverviewUpdated'; }
    public function broadcastWith(): array { return ['refresh' => true]; }
}
