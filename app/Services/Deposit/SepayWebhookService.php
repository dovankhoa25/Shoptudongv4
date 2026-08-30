<?php

namespace App\Services\Deposit;

use App\Models\AtmTopup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SepayWebhookService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{topup: AtmTopup, created: bool, balance: int}
     */
    public function process(array $payload): array
    {
        $userId = $this->userIdFromPaymentCode((string) $payload['code']);
        $providerTransactionId = (string) $payload['id'];
        $amount = (int) $payload['transferAmount'];

        return DB::transaction(function () use ($payload, $userId, $providerTransactionId, $amount): array {
            $user = User::query()->lockForUpdate()->find($userId);

            if (! $user) {
                throw ValidationException::withMessages([
                    'code' => 'Không tìm thấy người dùng từ mã thanh toán.',
                ]);
            }

            $topup = AtmTopup::query()->firstOrCreate(
                [
                    'provider' => 'sepay',
                    'provider_transaction_id' => $providerTransactionId,
                ],
                [
                    'user_id' => $user->id,
                    'gateway' => $payload['gateway'],
                    'transaction_at' => $payload['transactionDate'],
                    'account_number' => $payload['accountNumber'],
                    'sub_account' => $payload['subAccount'] ?? null,
                    'payment_code' => $payload['code'],
                    'content' => $payload['content'] ?? null,
                    'transfer_type' => $payload['transferType'],
                    'amount' => $amount,
                    'reference_code' => $payload['referenceCode'] ?? null,
                    'accumulated' => $payload['accumulated'] ?? null,
                    'description' => $payload['description'] ?? null,
                    'status' => AtmTopup::STATUS_COMPLETED,
                    'payload' => $payload,
                ],
            );

            if (! $topup->wasRecentlyCreated) {
                return [
                    'topup' => $topup,
                    'created' => false,
                    'balance' => (int) $user->balance,
                ];
            }

            $balanceBefore = (int) $user->balance;

            if ($balanceBefore > TransactionService::MAX_BALANCE - $amount) {
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
                'description' => 'Nạp tiền qua '.$payload['gateway'].' - SePay #'.$providerTransactionId.'.',
                'related_type' => AtmTopup::class,
                'related_id' => (string) $topup->id,
                'idempotency_key' => 'sepay:'.$providerTransactionId,
                'metadata' => [
                    'gateway' => $payload['gateway'],
                    'reference_code' => $payload['referenceCode'] ?? null,
                    'payment_code' => $payload['code'],
                ],
            ]);

            return [
                'topup' => $topup,
                'created' => true,
                'balance' => $balanceAfter,
            ];
        }, 3);
    }

    private function userIdFromPaymentCode(string $code): int
    {
        $prefix = trim((string) config('services.sepay.transfer_prefix', 'shop'));

        if ($prefix === '') {
            throw ValidationException::withMessages([
                'code' => 'Tiền tố mã thanh toán chưa được cấu hình.',
            ]);
        }

        $pattern = '/^'.preg_quote($prefix, '/').'([1-9][0-9]{0,18})$/i';

        if (preg_match($pattern, trim($code), $matches) !== 1) {
            throw ValidationException::withMessages([
                'code' => 'Mã thanh toán không đúng định dạng.',
            ]);
        }

        return (int) $matches[1];
    }
}
