<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public const MAX_BALANCE = 999999999999;

    public function __construct(private readonly UserRealtimeNotifier $realtime) {}

    public static function log(
        ?int $userId,
        string $type,
        int|float|string $amount,
        ?string $description = null,
        ?int $performedBy = null,
        $related = null,
        $relatedId = null,
        int|float|string|null $oldBalance = null,
        int|float|string|null $newBalance = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
    ): Transaction {
        $numericAmount = (int) $amount;

        if ($userId !== null && ($oldBalance === null || $newBalance === null)) {
            $currentBalance = User::query()->whereKey($userId)->value('balance');

            if ($currentBalance !== null) {
                $newBalance ??= (int) $currentBalance;
                $oldBalance ??= (int) $newBalance - $numericAmount;
            }
        }

        if ($related instanceof Model) {
            $relatedId ??= $related->getKey();
            $related = $related::class;
        }

        $attributes = [
            'user_id' => $userId,
            'performed_by' => $performedBy,
            'type' => $type,
            'amount' => $numericAmount,
            'balance_before' => $oldBalance === null ? null : (int) $oldBalance,
            'balance_after' => $newBalance === null ? null : (int) $newBalance,
            'description' => $description,
            'related_id' => $relatedId,
            'related_type' => is_string($related) ? $related : null,
            'metadata' => $metadata ?: null,
        ];

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return Transaction::query()->create($attributes);
        }

        $transaction = Transaction::query()->createOrFirst(
            ['idempotency_key' => trim($idempotencyKey)],
            $attributes,
        );

        if (! $transaction->wasRecentlyCreated) {
            self::ensureSameIdempotentTransaction($transaction, $attributes);
        }

        return $transaction;
    }

    /** @param array<string, mixed> $attributes */
    private static function ensureSameIdempotentTransaction(Transaction $transaction, array $attributes): void
    {
        $existing = [
            'user_id' => $transaction->user_id === null ? null : (int) $transaction->user_id,
            'performed_by' => $transaction->performed_by === null ? null : (int) $transaction->performed_by,
            'type' => $transaction->type,
            'amount' => (int) $transaction->amount,
            'balance_before' => $transaction->balance_before === null ? null : (int) $transaction->balance_before,
            'balance_after' => $transaction->balance_after === null ? null : (int) $transaction->balance_after,
            'description' => $transaction->description,
            'related_id' => $transaction->related_id === null ? null : (string) $transaction->related_id,
            'related_type' => $transaction->related_type,
            'metadata' => $transaction->metadata,
        ];
        $expected = $attributes;
        $expected['related_id'] = $attributes['related_id'] === null
            ? null
            : (string) $attributes['related_id'];

        if ($existing !== $expected) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Mã giao dịch này đã được dùng cho dữ liệu khác.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{transaction: Transaction, created: bool}
     */
    public function adjustBalance(
        User $target,
        User $actor,
        string $direction,
        int $amount,
        string $description,
        string $idempotencyKey,
        array $metadata = [],
    ): array {
        $result = DB::transaction(function () use ($target, $actor, $direction, $amount, $description, $idempotencyKey, $metadata): array {
            $user = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $type = $direction === 'credit'
                ? Transaction::TYPE_ADMIN_CREDIT
                : Transaction::TYPE_ADMIN_DEBIT;
            $signedAmount = $direction === 'credit' ? $amount : -$amount;
            $description = trim($description);

            $existing = Transaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id
                    || (int) $existing->performed_by !== (int) $actor->id
                    || $existing->type !== $type
                    || (int) $existing->amount !== $signedAmount
                    || $existing->description !== $description) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Mã giao dịch này đã được dùng cho một yêu cầu khác.',
                    ]);
                }

                return ['transaction' => $existing, 'created' => false];
            }

            $balanceBefore = (int) $user->balance;

            if ($signedAmount < 0 && $balanceBefore < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Số dư hiện tại không đủ để thực hiện khoản trừ này.',
                ]);
            }

            if ($signedAmount > 0 && $balanceBefore > self::MAX_BALANCE - $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Số dư sau khi cộng vượt giới hạn lưu trữ.',
                ]);
            }

            $balanceAfter = $balanceBefore + $signedAmount;
            $user->forceFill(['balance' => $balanceAfter])->save();

            $transaction = Transaction::query()->create([
                'user_id' => $user->id,
                'performed_by' => $actor->id,
                'type' => $type,
                'amount' => $signedAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata ?: null,
            ]);

            return ['transaction' => $transaction, 'created' => true];
        }, 3);

        if ($result['created']) {
            $transaction = $result['transaction'];
            $signedAmount = (int) $transaction->amount;

            $this->realtime->balanceChanged(
                userId: (int) $transaction->user_id,
                amount: $signedAmount,
                balance: (int) $transaction->balance_after,
                message: $signedAmount >= 0
                    ? 'Tài khoản của bạn vừa được cộng '.number_format($signedAmount).' VNĐ.'
                    : 'Tài khoản của bạn vừa bị trừ '.number_format(abs($signedAmount)).' VNĐ.',
            );
        }

        return $result;
    }
}
