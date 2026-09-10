<?php
namespace App\Events;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
class AdminViewPatched implements ShouldBroadcastNow {
    public function __construct(public readonly string $viewId,public readonly array $frame) {}
    public function broadcastOn(): array {return [new PrivateChannel('Admin.View.'.$this->viewId)];}
    public function broadcastAs(): string {return 'AdminViewPatched';}
    public function broadcastWith(): array {return $this->frame;}
}
