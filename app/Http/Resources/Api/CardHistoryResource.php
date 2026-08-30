<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'declared_value' => [
                'value' => (float) $this->declared_value,
                'formatted' => number_format((float) $this->declared_value) . ' VND',
            ],
            'amount_user' => [
                'value' => (float) $this->amount_user,
                'formatted' => number_format((float) $this->amount_user) . ' VND',
            ],
            'discount_rate_at_time' => [
                'value' => (float) $this->discount_rate_at_time,
                'formatted' => number_format((float) $this->discount_rate_at_time, 2) . '%',
            ],
            'code' => $this->code,
            'serial' => $this->serial,
            'status' => [
                'value' => $this->status,
                'label' => $this->statusLabel(),
            ],
            'note' => $this->note,
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Dang cho xu ly',
            'confirmed' => 'Da xac nhan',
            'completed' => 'Thanh cong',
            'failed' => 'That bai',
            default => 'Khong xac dinh',
        };
    }
}
