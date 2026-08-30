<?php

namespace App\Http\Resources\Admin\Transaction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'performed_by' => $this->whenLoaded('performer', fn () => $this->performer ? [
                'id' => $this->performer->id,
                'username' => $this->performer->username,
            ] : null),
            'type' => $this->type,
            'amount' => $this->amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'description' => $this->description,
            'related_id' => $this->related_id,
            'related_type' => $this->related_type,
            'created_at' => $this->created_at,
        ];
    }
}
