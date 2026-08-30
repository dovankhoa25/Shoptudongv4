<?php

namespace App\Http\Resources\Profile;

use App\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $declaredValue = (int) $this->declared_value;
        $amountUser = (int) $this->amount_user;
        $discountRate = (float) $this->discount_rate_at_time;

        return [
            'id' => $this->id,
            'telco' => $this->whenLoaded('cardType', fn () => $this->cardType?->telco),
            'declared_value' => [
                'value' => $declaredValue,
                'formatted' => number_format($declaredValue).' VND',
            ],
            'amount_user' => [
                'value' => $amountUser,
                'formatted' => number_format($amountUser).' VND',
            ],
            'discount_rate_at_time' => [
                'value' => $discountRate,
                'formatted' => number_format($discountRate, 2).'%',
            ],
            'code' => $this->code,
            'serial' => $this->serial,
            'status' => [
                'value' => $this->status,
                'label' => $this->statusLabel(),
            ],
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            Card::STATUS_PENDING => 'Đang chờ xử lý',
            Card::STATUS_CONFIRMED => 'Đã xác nhận',
            Card::STATUS_COMPLETED => 'Thành công',
            Card::STATUS_FAILED => 'Thất bại',
            default => 'Không xác định',
        };
    }
}
