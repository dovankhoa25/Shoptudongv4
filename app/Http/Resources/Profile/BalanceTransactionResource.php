<?php

namespace App\Http\Resources\Profile;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $amount = (int) $this->amount;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title(),
            'direction' => $amount >= 0 ? 'credit' : 'debit',
            'amount' => $amount,
            'absolute_amount' => abs($amount),
            'currency' => 'VND',
            'balance_before' => (int) $this->balance_before,
            'balance_after' => (int) $this->balance_after,
            'description' => $this->description,
            'source' => $this->source(),
            'reference' => $this->related_type && $this->related_id ? [
                'type' => class_basename($this->related_type),
                'id' => (string) $this->related_id,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function title(): string
    {
        return match ($this->type) {
            Transaction::TYPE_ADMIN_CREDIT => 'Admin cộng tiền',
            Transaction::TYPE_ADMIN_DEBIT => 'Admin trừ tiền',
            Transaction::TYPE_CARD_DEPOSIT => 'Nạp tiền bằng thẻ',
            Transaction::TYPE_BANK_DEPOSIT => 'Nạp tiền qua ngân hàng',
            Transaction::TYPE_GOLD_ORDER_REFUND => 'Hoàn tiền đơn mua vàng',
            Transaction::TYPE_GOLD_IMPORT_CREDIT => 'Thanh toán đơn bán vàng',
            Transaction::TYPE_GEM_ORDER_REFUND => 'Hoàn tiền đơn mua ngọc',
            default => 'Giao dịch số dư',
        };
    }

    private function source(): string
    {
        if ($this->performed_by === null) {
            return 'system';
        }

        return (int) $this->performed_by === (int) $this->user_id
            ? 'user'
            : 'admin';
    }
}
