<?php

namespace App\Http\Resources\Admin\Deposit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ] : null),
            'card_type' => $this->whenLoaded('cardType', fn () => $this->cardType ? [
                'id' => $this->cardType->id,
                'telco' => $this->cardType->telco,
            ] : null),
            'declared_value' => (int) $this->declared_value,
            'value' => $this->value === null ? null : (int) $this->value,
            'amount_user' => (int) $this->amount_user,
            'amount_api' => $this->amount_api === null ? null : (int) $this->amount_api,
            'difference' => $this->difference === null ? null : (float) $this->difference,
            'discount_rate_at_time' => (float) $this->discount_rate_at_time,
            'code' => $this->mask((string) $this->code),
            'serial' => $this->mask((string) $this->serial),
            'trans_id' => $this->trans_id,
            'status' => $this->status,
            'loaded_type' => (bool) $this->loaded_type,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function mask(string $value): string
    {
        if (mb_strlen($value) <= 4) {
            return str_repeat('*', mb_strlen($value));
        }

        return str_repeat('*', max(mb_strlen($value) - 4, 4)).mb_substr($value, -4);
    }
}
