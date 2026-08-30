<?php

namespace App\Http\Resources\Transaction;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'user'         => $this->user ? [
                'id'   => $this->user->id,
                'username' => $this->user->username,
            ] : null,
            'performed_by' => $this->performer ? [
                'id'   => $this->performer->id,
                'username' => $this->performer->username,
            ] : null,
            'type'         => $this->type,
            'amount'       => $this->amount,
            'old_balance'       => $this->old_balance,
            'new_balance'       => $this->new_balance,
            'description'  => $this->description,
            'related_id'   => $this->related_id,
            'related_type' => $this->related_type,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
