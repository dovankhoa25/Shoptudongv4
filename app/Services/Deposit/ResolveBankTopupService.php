<?php

namespace App\Services\Deposit;

use App\Models\AtmTopup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ResolveBankTopupService
{
    public function __construct(private readonly BankTopupCreditor $creditor) {}

    /** @return array{topup: AtmTopup, balance: int} */
    public function resolve(AtmTopup $topup, int $userId, User $actor, array $metadata = []): array
    {
        return DB::transaction(function () use ($topup, $userId, $actor, $metadata): array {
            $lockedTopup = AtmTopup::query()->lockForUpdate()->findOrFail($topup->id);
            abort_unless($lockedTopup->status === AtmTopup::STATUS_NEEDS_REVIEW, 409, 'Đơn nạp tiền đã được xử lý.');

            $identity = ($lockedTopup->provider === 'dvkpay' ? 'gpmpay' : $lockedTopup->provider).':'.$lockedTopup->provider_transaction_id;
            $hasCredit = Transaction::withoutUserOwnedScope()->where(function ($query) use ($lockedTopup, $identity) {
                $query->where('idempotency_key', $identity)
                    ->orWhere(fn ($query) => $query->where('related_type', AtmTopup::class)->where('related_id', (string) $lockedTopup->id));
            })->exists();
            abort_if($hasCredit, 409, 'Đơn đã có lịch sử cộng tiền; không thể cộng lại.');

            $user = User::query()->lockForUpdate()->find($userId);
            if (! $user) {
                throw ValidationException::withMessages(['user_id' => 'Không tìm thấy user đang hoạt động.']);
            }
            Gate::forUser($actor)->authorize('adjustBalance', $user);

            $balance = $this->creditor->credit(
                $lockedTopup,
                $user,
                $actor,
                'Xử lý nạp tiền ID #'.$lockedTopup->id.' - giao dịch '.$lockedTopup->provider_transaction_id.'.',
                [...$metadata,
                    'source' => 'admin-deposit-resolution',
                    'original_review_reason' => $lockedTopup->review_reason,
                    'original_user_id' => $lockedTopup->user_id],
            );
            $lockedTopup->forceFill([
                'user_id' => $user->id,
                'status' => AtmTopup::STATUS_COMPLETED,
                'review_reason' => null,
            ])->save();

            return ['topup' => $lockedTopup, 'balance' => $balance];
        }, 3);
    }
}
