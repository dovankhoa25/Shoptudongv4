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
            'success' => 'đã hoàn thành',
            'failed' => 'thất bại',
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

    public function balanceChanged(
        int $userId,
        int $amount,
        int $balance,
        string $message,
        ?int $remainingDailyLimit = null,
    ): void {
        if(\Illuminate\Support\Facades\DB::transactionLevel()>0) {
            \Illuminate\Support\Facades\DB::afterCommit(fn()=>$this->publishBalance($userId,$amount,$balance,$message,$remainingDailyLimit));return;
        }
        $this->publishBalance($userId,$amount,$balance,$message,$remainingDailyLimit);
    }
    private function publishBalance(int $userId,int $amount,int $balance,string $message,?int $remainingDailyLimit): void {
        try {
        $snapshot=UserBalanceSnapshot::read($userId);
        if(!$snapshot)return;
        $key='balance:notified:'.$userId.':'.$snapshot['balance_revision'];
        if(!\Illuminate\Support\Facades\Cache::add($key,true,3600))return;
        $payload = ['amount'=>$amount,...$snapshot];
        if ($remainingDailyLimit !== null) {
            $payload['remaining_daily_limit'] = max(0, $remainingDailyLimit);
        }

        $sent=$this->send(new UserEvent(
            userId: $userId,
            type: 'update_balance',
            message: $message,
            payload: $payload,
        ));
        if(!$sent) \Illuminate\Support\Facades\Cache::forget($key);
        } catch(Throwable $exception) {Log::warning('Balance realtime failed',['user_id'=>$userId,'error'=>$exception->getMessage()]);}
    }

    private function send(UserEvent $event): bool
    {
        try {
            broadcast($event);
            return true;
        } catch (Throwable $exception) {
            // Realtime không được làm hỏng giao dịch chính.
            Log::warning('User realtime broadcast failed', [
                'user_id' => $event->userId,
                'type' => $event->type,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }
}
