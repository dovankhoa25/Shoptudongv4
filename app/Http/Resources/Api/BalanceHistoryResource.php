<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => [
                'value' => $this->type,
                'label' => $this->typeLabel(),
            ],
            'amount' => [
                'value' => (float) $this->amount,
                'formatted' => number_format((float) $this->amount) . ' VND',
            ],
            'balance' => [
                'old' => [
                    'value' => $this->old_balance !== null ? (float) $this->old_balance : null,
                    'formatted' => $this->old_balance !== null ? number_format((float) $this->old_balance) . ' VND' : null,
                ],
                'new' => [
                    'value' => $this->new_balance !== null ? (float) $this->new_balance : null,
                    'formatted' => $this->new_balance !== null ? number_format((float) $this->new_balance) . ' VND' : null,
                ],
            ],
            'description' => $this->description,
            'performed_by' => $this->performer ? [
                'id' => $this->performer->id,
                'username' => $this->performer->username,
            ] : null,
            'related' => [
                'id' => $this->related_id,
                'type' => $this->related_type,
            ],
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function typeLabel(): string
    {
        return match ($this->type) {
            'deposit' => 'Nap tien',
            'withdraw' => 'Rut tien',
            'purchase' => 'Mua hang',
            'refund' => 'Hoan tien',
            'carot_recharge' => 'Nap carot tu dong',
            'carot_recharge_refund' => 'Hoan tien nap carot',
            'admin_add' => 'Admin cong tien',
            'admin_subtract' => 'Admin tru tien',
            default => $this->type ?: 'Khong xac dinh',
        };
    }
}
