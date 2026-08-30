<?php

namespace App\Services\Deposit;

use App\Models\Card;
use App\Models\CardType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CardRechargeService
{
    /**
     * @param  array{amount: int, card_code: string, card_serial: string}  $data
     */
    public function submit(User $user, CardType $cardType, array $data): Card
    {
        $partnerId = (string) config('services.card_partner.id');
        $partnerKey = (string) config('services.card_partner.key');
        $url = (string) config('services.card_partner.url');

        if ($partnerId === '' || $partnerKey === '' || $url === '') {
            throw new RuntimeException('Dịch vụ nạp thẻ chưa được cấu hình.');
        }

        try {
            $card = Card::query()->create([
                'declared_value' => $data['amount'],
                'discount_rate_at_time' => $cardType->discount_rate,
                'amount_user' => 0,
                'code' => $data['card_code'],
                'serial' => $data['card_serial'],
                'user_id' => $user->id,
                'card_type_id' => $cardType->id,
                'status' => Card::STATUS_PENDING,
                'loaded_type' => false,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateCard($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'card_code' => 'Thẻ với mã và serial này đã được gửi trước đó.',
            ]);
        }

        $payload = [
            'telco' => strtoupper($cardType->telco),
            'code' => $card->code,
            'serial' => $card->serial,
            'amount' => (int) $card->declared_value,
            'request_id' => $card->id,
            'partner_id' => $partnerId,
            'sign' => md5($partnerKey.$card->code.$card->serial),
            'command' => 'charging',
        ];

        try {
            $response = Http::timeout((int) config('services.card_partner.timeout', 30))
                ->asForm()
                ->post($url, $payload);
            $result = $response->json();

            if (! $response->successful() || ! is_array($result)) {
                $result = ['status' => 100, 'message' => 'Đối tác không phản hồi hợp lệ.'];
            }
        } catch (Throwable $exception) {
            Log::warning('Card partner request failed', [
                'card_id' => $card->id,
                'error' => $exception->getMessage(),
            ]);
            $result = ['status' => 100, 'message' => 'Không thể kết nối đối tác.'];
        }

        $partnerStatus = (int) ($result['status'] ?? 99);

        if ($partnerStatus === 1 || $partnerStatus === 2) {
            return $this->applyResult($card->id, $result)['card'];
        }

        $card->update([
            'trans_id' => $result['trans_id'] ?? null,
            'status' => $this->mapStatus($partnerStatus),
            'value' => $result['value'] ?? null,
            'amount_api' => $result['amount'] ?? null,
            'note' => $this->statusNote($partnerStatus, $result['message'] ?? null),
        ]);

        return $card->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{card: Card, credited: bool, duplicate: bool, balance: int|null}
     */
    public function processCallback(array $payload): array
    {
        $partnerKey = (string) config('services.card_partner.key');

        if ($partnerKey === '') {
            throw new RuntimeException('Dịch vụ nạp thẻ chưa được cấu hình.');
        }

        $expectedSign = md5($partnerKey.$payload['code'].$payload['serial']);

        if (! hash_equals($expectedSign, (string) $payload['callback_sign'])) {
            throw new InvalidArgumentException('Invalid signature');
        }

        $card = Card::query()
            ->where('code', $payload['code'])
            ->where('serial', $payload['serial'])
            ->when(
                isset($payload['request_id']),
                fn ($query) => $query->whereKey($payload['request_id'])
            )
            ->first();

        if (! $card) {
            throw (new ModelNotFoundException)->setModel(Card::class);
        }

        return $this->applyResult($card->id, $payload);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{card: Card, credited: bool, duplicate: bool, balance: int|null}
     */
    private function applyResult(int $cardId, array $result): array
    {
        return DB::transaction(function () use ($cardId, $result): array {
            $card = Card::query()->with('cardType')->lockForUpdate()->findOrFail($cardId);

            if ($card->status === Card::STATUS_COMPLETED || (int) $card->amount_user > 0) {
                return [
                    'card' => $card,
                    'credited' => false,
                    'duplicate' => true,
                    'balance' => (int) $card->user()->value('balance'),
                ];
            }

            $partnerStatus = (int) ($result['status'] ?? 99);
            $amountUser = 0;
            $balanceAfter = null;

            if ($partnerStatus === 1) {
                $value = (int) ($result['value'] ?? 0);

                if ($value <= 0) {
                    throw ValidationException::withMessages([
                        'value' => 'Mệnh giá thực nhận từ đối tác không hợp lệ.',
                    ]);
                }

                $discountBasisPoints = (int) round((float) $card->discount_rate_at_time * 100);

                if ($discountBasisPoints < 0 || $discountBasisPoints >= 10000) {
                    throw ValidationException::withMessages([
                        'value' => 'Mức chiết khấu của loại thẻ không hợp lệ.',
                    ]);
                }

                $amountUser = intdiv($value * (10000 - $discountBasisPoints), 10000);
                $user = User::query()->lockForUpdate()->findOrFail($card->user_id);
                $balanceBefore = (int) $user->balance;

                if ($balanceBefore > TransactionService::MAX_BALANCE - $amountUser) {
                    throw ValidationException::withMessages([
                        'value' => 'Số dư sau khi nạp vượt giới hạn lưu trữ.',
                    ]);
                }

                $balanceAfter = $balanceBefore + $amountUser;
                $user->forceFill(['balance' => $balanceAfter])->save();

                Transaction::query()->create([
                    'user_id' => $user->id,
                    'performed_by' => null,
                    'type' => Transaction::TYPE_CARD_DEPOSIT,
                    'amount' => $amountUser,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => 'Nạp thẻ '.strtoupper((string) $card->cardType?->telco).' mệnh giá '.number_format($value).' VNĐ.',
                    'related_type' => Card::class,
                    'related_id' => (string) $card->id,
                    'idempotency_key' => 'card:'.$card->id,
                    'metadata' => [
                        'telco' => $card->cardType?->telco,
                        'declared_value' => (int) $card->declared_value,
                        'actual_value' => $value,
                        'discount_rate' => (string) $card->discount_rate_at_time,
                    ],
                ]);
            }

            $amountApi = isset($result['amount']) ? (int) $result['amount'] : null;
            $difference = $amountApi === null ? 0 : max(0, $amountApi - $amountUser);

            $card->update([
                'trans_id' => $result['trans_id'] ?? $card->trans_id,
                'status' => $this->mapStatus($partnerStatus),
                'value' => $result['value'] ?? $card->value,
                'amount_api' => $amountApi,
                'amount_user' => $amountUser,
                'difference' => $difference,
                'note' => $this->statusNote($partnerStatus, $result['message'] ?? null),
            ]);

            return [
                'card' => $card->refresh(),
                'credited' => $amountUser > 0,
                'duplicate' => false,
                'balance' => $balanceAfter,
            ];
        }, 3);
    }

    private function mapStatus(int $partnerStatus): string
    {
        return match ($partnerStatus) {
            1 => Card::STATUS_COMPLETED,
            2 => Card::STATUS_CONFIRMED,
            3, 100 => Card::STATUS_FAILED,
            default => Card::STATUS_PENDING,
        };
    }

    private function statusNote(int $partnerStatus, mixed $message): string
    {
        $message = is_scalar($message) ? (string) $message : null;

        return match ($partnerStatus) {
            1 => 'Thành công đúng mệnh giá',
            2 => 'Thành công sai mệnh giá, chờ xử lý thủ công',
            3 => 'Thẻ lỗi',
            4 => 'Hệ thống đối tác đang bảo trì',
            99 => 'Thẻ đang chờ xử lý',
            100 => 'Gửi thẻ thất bại',
            default => $message ?: 'Trạng thái không xác định',
        };
    }

    private function isDuplicateCard(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
