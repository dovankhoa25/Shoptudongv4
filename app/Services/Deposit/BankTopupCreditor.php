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
    /** The caller must own a newly created topup and hold the user's row lock. */
    public function credit(AtmTopup $topup, User $user): int
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

        Transaction::query()->create([
            'user_id' => $user->id,
            'performed_by' => null,
            'type' => Transaction::TYPE_BANK_DEPOSIT,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => 'Nạp tiền qua '.$topup->gateway.' - atm #'.$topup->provider_transaction_id.'.',
            'related_type' => AtmTopup::class,
            'related_id' => (string) $topup->id,
            'idempotency_key' => $topup->provider.':'.$topup->provider_transaction_id,
            'metadata' => [
                'gateway' => $topup->gateway,
                'reference_code' => $topup->reference_code,
                'payment_code' => $topup->payment_code,
            ],
        ]);

        return $balanceAfter;
    }
}
