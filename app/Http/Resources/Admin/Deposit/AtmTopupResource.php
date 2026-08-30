<?php

namespace App\Http\Resources\Admin\Deposit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtmTopupResource extends JsonResource
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
            'provider' => $this->provider,
            'provider_transaction_id' => $this->provider_transaction_id,
            'gateway' => $this->gateway,
            'transaction_at' => $this->transaction_at?->toISOString(),
            'account_number' => $this->mask((string) $this->account_number),
            'sub_account' => $this->sub_account ? $this->mask((string) $this->sub_account) : null,
            'payment_code' => $this->payment_code,
            'content' => $this->content,
            'transfer_type' => $this->transfer_type,
            'amount' => (int) $this->amount,
            'reference_code' => $this->reference_code,
            'accumulated' => $this->accumulated === null ? null : (int) $this->accumulated,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
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
