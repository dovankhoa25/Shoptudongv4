<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class UserEvent implements ShouldBroadcastNow
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
            'payload' => $this->payload,
        ];
    }

    public function broadcastAs(): string
    {
        return 'UserEvent';
    }
}
