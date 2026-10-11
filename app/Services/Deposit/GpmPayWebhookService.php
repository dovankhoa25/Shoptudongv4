<?php

namespace App\Services\Deposit;

use App\Models\AtmTopup;
use App\Models\User;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GpmPayWebhookService
{
    public function __construct(private readonly BankTopupCreditor $creditor) {}

    /**
     * Only authenticated, validated, REAL incoming transfers reach this service.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $originalPayload
     * @return array{topup: AtmTopup, created: bool, credited: bool, balance: ?int}
     */
    public function process(array $payload, array $originalPayload): array
    {
        $content = $payload['content'] ?? $payload['description'] ?? '';
        [$code, $userId, $reason] = $this->paymentCode((string) $content);

        // Keep old source labels compatible with webhook retries after the rename.
        $legacy = AtmTopup::query()->where('provider', 'gpmpay')
            ->where('provider_transaction_id', strtolower($payload['id']))->first();

        if ($legacy) {
            return ['topup' => $legacy, 'created' => false, 'credited' => false, 'balance' => null];
        }

        return DB::transaction(function () use ($payload, $originalPayload, $content, $code, $userId, $reason): array {
            // The provider's UUID is unique in the database, including unmatched transfers.
            // Retries never replace the first payload or turn an unmatched transfer into a credit.
            $topup = AtmTopup::query()->createOrFirst(
                ['provider' => 'dvkpay', 'provider_transaction_id' => strtolower($payload['id'])],
                [
                    'user_id' => null,
                    'gateway' => $payload['gateway'],
                    'transaction_at' => CarbonImmutable::parse($payload['transactionDate'])->setTimezone(config('app.timezone')),
                    'account_number' => $payload['accountNumber'],
                    'sub_account' => $payload['subAccount'] ?? null,
                    'payment_code' => $code,
                    'content' => $content,
                    'transfer_type' => $payload['transferType'],
                    'amount' => (int) $payload['transferAmount'],
                    'reference_code' => $payload['referenceCode'] ?? null,
                    'accumulated' => $payload['accumulated'] ?? null,
                    'description' => $payload['description'] ?? null,
                    'status' => AtmTopup::STATUS_NEEDS_REVIEW,
                    'review_reason' => $reason,
                    'payload' => $originalPayload,
                ],
            );

            if (! $topup->wasRecentlyCreated) {
                return ['topup' => $topup, 'created' => false, 'credited' => false, 'balance' => null];
            }

            $user = $userId === null ? null : User::query()->lockForUpdate()->find($userId);

            if ($reason === null && ! $user) {
                $reason = 'Không tìm thấy người dùng từ mã thanh toán.';
            }

            if ($user && (int) $user->balance > TransactionService::MAX_BALANCE - (int) $topup->amount) {
                $reason = 'Số dư sau khi nạp vượt giới hạn lưu trữ.';
            }

            if ($reason !== null) {
                $topup->forceFill(['review_reason' => $reason])->save();

                return ['topup' => $topup, 'created' => true, 'credited' => false, 'balance' => null];
            }

            $topup->forceFill([
                'user_id' => $user->id,
                'status' => AtmTopup::STATUS_COMPLETED,
                'review_reason' => null,
            ])->save();
            $balance = $this->creditor->credit($topup, $user);

            return ['topup' => $topup, 'created' => true, 'credited' => true, 'balance' => $balance];
        }, 3);
    }

    /** @return array{?string, ?int, ?string} */
    private function paymentCode(string $content): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_])(?:shop|vang)([0-9]+)(?![\p{L}\p{N}_])/iu', $content, $matches, PREG_SET_ORDER);
        $codes = [];

        foreach ($matches as $match) {
            $codes[strtolower($match[0])] = $match[1];
        }

        if ($codes === []) {
            return [null, null, 'Nội dung không có mã shop<ID> hoặc vang<ID> hợp lệ.'];
        }

        if (count($codes) !== 1) {
            return [null, null, 'Nội dung có nhiều mã thanh toán khác nhau.'];
        }

        $code = array_key_first($codes);
        $digits = $codes[$code];
        $max = (string) PHP_INT_MAX;

        if ($digits[0] === '0' || strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return [strlen($code) <= 100 ? $code : null, null, 'ID người dùng trong mã thanh toán không hợp lệ.'];
        }

        return [$code, (int) $digits, null];
    }
}
