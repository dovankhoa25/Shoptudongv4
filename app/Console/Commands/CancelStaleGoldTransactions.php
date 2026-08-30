<?php

namespace App\Console\Commands;

use App\Models\GemTransaction;
use App\Models\GoldTransaction;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Services\UserRealtimeNotifier;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelStaleGoldTransactions extends Command
{
    protected $signature = 'gold:cancel-stale';

    protected $description = 'Đóng đơn vàng/ngọc pending quá hạn và hoàn tiền sau khoảng chờ an toàn';

    public function __construct(private readonly UserRealtimeNotifier $realtime)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $goldCancelled = $this->closeExpiredPendingOrders(
            modelClass: GoldTransaction::class,
            pendingTimeout: $this->positiveConfig('trading.gold_order_pending_timeout_minutes', 10),
            orderType: 'gold',
        );
        $goldRefunded = $this->refundElapsedCancellations(
            modelClass: GoldTransaction::class,
            refundGrace: $this->positiveConfig('trading.gold_order_refund_grace_minutes', 5),
            orderType: 'gold',
            transactionType: Transaction::TYPE_GOLD_ORDER_REFUND,
            idempotencyPrefix: 'gold-order-timeout-refund',
            orderLabel: 'vàng',
        );

        $gemCancelled = $this->closeExpiredPendingOrders(
            modelClass: GemTransaction::class,
            pendingTimeout: $this->positiveConfig('trading.gem_order_pending_timeout_minutes', 10),
            orderType: 'gem',
        );
        $gemRefunded = $this->refundElapsedCancellations(
            modelClass: GemTransaction::class,
            refundGrace: $this->positiveConfig('trading.gem_order_refund_grace_minutes', 5),
            orderType: 'gem',
            transactionType: Transaction::TYPE_GEM_ORDER_REFUND,
            idempotencyPrefix: 'gem-order-timeout-refund',
            orderLabel: 'ngọc',
            markRefundedStatus: true,
        );

        $this->info(
            "Vàng: đóng {$goldCancelled}, hoàn {$goldRefunded}. "
            ."Ngọc: đóng {$gemCancelled}, hoàn {$gemRefunded}.",
        );

        return self::SUCCESS;
    }

    /**
     * @param  class-string<GoldTransaction|GemTransaction>  $modelClass
     */
    private function closeExpiredPendingOrders(
        string $modelClass,
        int $pendingTimeout,
        string $orderType,
    ): int {
        $cancelledCount = 0;
        $expiredBefore = now()->subMinutes($pendingTimeout);

        $modelClass::query()
            ->where('status', 'pending')
            ->whereNull('cancel_requested_at')
            ->where('created_at', '<=', $expiredBefore)
            ->select('id')
            ->chunkById(100, function ($transactions) use (
                &$cancelledCount,
                $expiredBefore,
                $modelClass,
                $orderType,
            ): void {
                foreach ($transactions as $candidate) {
                    $cancelled = DB::transaction(function () use ($candidate, $expiredBefore, $modelClass): ?Model {
                        $transaction = $modelClass::query()
                            ->whereKey($candidate->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $transaction
                            || $transaction->status !== 'pending'
                            || $transaction->cancel_requested_at !== null
                            || $transaction->created_at?->gt($expiredBefore)) {
                            return null;
                        }

                        $transaction->forceFill([
                            'status' => 'cancelled',
                            'updated_by' => 'web',
                            'last_synced_at' => now(),
                            'cancel_requested_at' => now(),
                        ])->save();

                        return $transaction;
                    }, 3);

                    if (! $cancelled) {
                        continue;
                    }

                    $cancelledCount++;
                    $this->realtime->orderStatus(
                        userId: (int) $cancelled->user_id,
                        orderType: $orderType,
                        orderId: (int) $cancelled->id,
                        status: 'cancelled',
                        botId: null,
                    );
                }
            });

        return $cancelledCount;
    }

    /**
     * @param  class-string<GoldTransaction|GemTransaction>  $modelClass
     */
    private function refundElapsedCancellations(
        string $modelClass,
        int $refundGrace,
        string $orderType,
        string $transactionType,
        string $idempotencyPrefix,
        string $orderLabel,
        bool $markRefundedStatus = false,
    ): int {
        $refundedCount = 0;
        $refundBefore = now()->subMinutes($refundGrace);
        $query = $modelClass::query()
            ->where('status', 'cancelled')
            ->whereNull('refunded_at')
            ->whereNotNull('cancel_requested_at')
            ->where('cancel_requested_at', '<=', $refundBefore);

        if ($modelClass === GoldTransaction::class) {
            $query->where('type', GoldTransaction::TYPE_ORDER);
        }

        $query->select('id')->chunkById(100, function ($transactions) use (
            &$refundedCount,
            $refundBefore,
            $modelClass,
            $orderType,
            $transactionType,
            $idempotencyPrefix,
            $orderLabel,
            $markRefundedStatus,
        ): void {
            foreach ($transactions as $candidate) {
                $refunded = DB::transaction(function () use (
                    $candidate,
                    $refundBefore,
                    $modelClass,
                    $transactionType,
                    $idempotencyPrefix,
                    $orderLabel,
                    $markRefundedStatus,
                ): ?array {
                    $transaction = $modelClass::query()
                        ->whereKey($candidate->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $transaction
                        || $transaction->status !== 'cancelled'
                        || $transaction->refunded_at !== null
                        || $transaction->cancel_requested_at === null
                        || $transaction->cancel_requested_at->gt($refundBefore)
                        || ($transaction instanceof GoldTransaction
                            && $transaction->type !== GoldTransaction::TYPE_ORDER)) {
                        return null;
                    }

                    $idempotencyKey = "{$idempotencyPrefix}:{$transaction->id}";
                    $existing = Transaction::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $this->markRefunded($transaction, $markRefundedStatus);

                        return null;
                    }

                    $amount = (int) $transaction->amount_vnd;
                    if ($amount <= 0) {
                        $this->markRefunded($transaction, $markRefundedStatus);

                        return null;
                    }

                    $user = $transaction->user()->lockForUpdate()->firstOrFail();
                    $balanceBefore = (int) $user->balance;
                    if ($balanceBefore > TransactionService::MAX_BALANCE - $amount) {
                        throw ValidationException::withMessages([
                            'balance' => "Không thể hoàn tiền đơn {$orderLabel} #{$transaction->id}: số dư vượt giới hạn.",
                        ]);
                    }

                    $balanceAfter = $balanceBefore + $amount;
                    $user->forceFill(['balance' => $balanceAfter])->save();

                    TransactionService::log(
                        userId: (int) $user->id,
                        type: $transactionType,
                        amount: $amount,
                        description: "Hoàn tiền tự động đơn mua {$orderLabel} quá hạn #{$transaction->id} - Nhân vật: {$transaction->character_name}",
                        performedBy: null,
                        related: $transaction,
                        relatedId: $transaction->id,
                        oldBalance: $balanceBefore,
                        newBalance: $balanceAfter,
                        idempotencyKey: $idempotencyKey,
                        metadata: [
                            'source' => 'scheduler',
                            'reason' => 'stale_order',
                            'character_name' => $transaction->character_name,
                        ],
                    );

                    $this->markRefunded($transaction, $markRefundedStatus);

                    return [
                        'transaction' => $transaction,
                        'amount' => $amount,
                        'balance' => $balanceAfter,
                    ];
                }, 3);

                if (! $refunded) {
                    continue;
                }

                $refundedCount++;
                $transaction = $refunded['transaction'];
                $this->realtime->balanceChanged(
                    userId: (int) $transaction->user_id,
                    amount: (int) $refunded['amount'],
                    balance: (int) $refunded['balance'],
                    message: "Đơn #{$transaction->id} đã được hoàn tiền do quá thời gian xử lý.",
                );

                if ($markRefundedStatus) {
                    $this->realtime->orderStatus(
                        userId: (int) $transaction->user_id,
                        orderType: $orderType,
                        orderId: (int) $transaction->id,
                        status: 'refunded',
                        botId: null,
                    );
                }
            }
        });

        return $refundedCount;
    }

    private function markRefunded(Model $transaction, bool $markRefundedStatus): void
    {
        $attributes = [
            'updated_by' => 'web',
            'last_synced_at' => now(),
            'refunded_at' => now(),
        ];
        if ($markRefundedStatus) {
            $attributes['status'] = GemTransaction::STATUS_REFUNDED;
        }

        $transaction->forceFill($attributes)->save();
    }

    private function positiveConfig(string $key, int $default): int
    {
        return max((int) config($key, $default), 1);
    }
}
