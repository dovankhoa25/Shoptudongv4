<?php
namespace App\Events;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Each payload belongs to exactly one authenticated buyer. */
class NroOrdersPatched implements ShouldBroadcastNow
{
    public function __construct(public readonly int $buyerId, public readonly array $orders, public readonly bool $balanceChanged = false, public readonly bool $refresh = false) {}
    public function broadcastOn(): array { return [new PrivateChannel('User.'.$this->buyerId)]; }
    public function broadcastAs(): string { return 'NroOrdersPatched'; }
    public function broadcastWith(): array { return ['buyer_id'=>$this->buyerId,'orders'=>$this->orders,'balance_changed'=>$this->balanceChanged,'refresh'=>$this->refresh]; }
}
