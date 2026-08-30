<?php

namespace App\Services;

use App\Events\UserEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserRealtimeNotifier
{
    public function orderStatus(
        int $userId,
        string $orderType,
        int $orderId,
        string $status,
        ?int $botId = null,
    ): void {
        $statusLabel = match ($status) {
            'pending' => 'đang chờ xử lý',
            'processing' => 'đang được xử lý',
            'completed' => 'đã hoàn thành',
            'cancelled' => 'đã hủy',
            'refunded' => 'đã hoàn tiền',
            default => $status,
        };

        $this->send(new UserEvent(
            userId: $userId,
            type: 'order_status',
            message: "Đơn #{$orderId} {$statusLabel}.",
            payload: [
                'order_id' => $orderId,
                'order_type' => $orderType,
                'status' => $status,
                'bot_id' => $botId,
            ],
        ));
    }

    public function balanceChanged(int $userId, int $amount, int $balance, string $message): void
    {
        $this->send(new UserEvent(
            userId: $userId,
            type: 'update_balance',
            message: $message,
            payload: [
                'amount' => $amount,
                'balance' => $balance,
            ],
        ));
    }

    private function send(UserEvent $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $exception) {
            // Realtime không được làm hỏng giao dịch chính.
            Log::warning('User realtime broadcast failed', [
                'user_id' => $event->userId,
                'type' => $event->type,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
