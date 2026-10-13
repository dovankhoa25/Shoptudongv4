<?php

namespace App\Services\Deposit;

use App\Models\AtmTopup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankTopupCreditor
{
    /** The caller must hold the user's lock and exclusively own the uncredited topup. */
    public function credit(AtmTopup $topup, User $user, ?User $actor = null, ?string $description = null, array $metadata = []): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Bank topups must be credited inside a database transaction.');
        }

        $amount = (int) $topup->amount;
        $balanceBefore = (int) $user->balance;

        if ($amount < 1 || $balanceBefore > TransactionService::MAX_BALANCE - $amount) {
            throw ValidationException::withMessages([
                'transferAmount' => 'Số dư sau khi nạp vượt giới hạn lưu trữ.',
            ]);
        }

        $balanceAfter = $balanceBefore + $amount;
        $user->forceFill(['balance' => $balanceAfter])->save();

        Transaction::withoutUserOwnedScope()->create([
            'user_id' => $user->id,
            'performed_by' => $actor?->id,
            'type' => Transaction::TYPE_BANK_DEPOSIT,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description ?? 'Nạp tiền qua '.$topup->gateway.' - atm #'.$topup->provider_transaction_id.'.',
            'related_type' => AtmTopup::class,
            'related_id' => (string) $topup->id,
            // The source label can change; the existing payment identity must remain stable.
            'idempotency_key' => ($topup->provider === 'dvkpay' ? 'gpmpay' : $topup->provider).':'.$topup->provider_transaction_id,
            'metadata' => [
                'gateway' => $topup->gateway,
                'reference_code' => $topup->reference_code,
                'payment_code' => $topup->payment_code,
                ...$metadata,
            ],
        ]);

        return $balanceAfter;
    }
}
