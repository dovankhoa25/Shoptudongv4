<?php
namespace App\Events;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
class TradingBotActivity implements ShouldBroadcastNow {
    public function __construct(public array $activity) {}
    public function broadcastOn(): array { return [new Channel('Trading.Bots')]; }
    public function broadcastAs(): string { return 'TradingBotActivity'; }
    public function broadcastWith(): array { return $this->activity; }
}

