<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class UserEvent implements ShouldBroadcastNow, \Illuminate\Contracts\Events\ShouldDispatchAfterCommit
{
    use SerializesModels;

    public int $userId;
    public string $type;
    public string $message;
    public array $payload;

    public function __construct(int $userId, string $type, string $message, array $payload = [])
    {
        $this->userId = $userId;
        $this->type = $type;
        $this->message = $message;
        $this->payload = $payload;
    }

    public function broadcastOn()
    {
        return new PrivateChannel("User.{$this->userId}");
    }

    public function broadcastWith(): array
    {
        return [
            'userId' => $this->userId,
            'type' => $this->type,
            'message' => $this->message,
            'payload' => $this->type==='update_balance' ? array_merge($this->payload, \App\Services\UserBalanceSnapshot::read($this->userId)) : $this->payload,
        ];
    }

    public function broadcastAs(): string
    {
        return 'UserEvent';
    }
}
