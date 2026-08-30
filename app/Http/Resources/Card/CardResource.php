<?php

namespace App\Http\Resources\Card;

use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->id,
            'declared_value'   => $this->declared_value,
            'value'            => $this->value,
            'amount_user'      => $this->amount_user,
            'amount_api'       => $this->amount_api,
            'discount_rate_at_time' => $this->discount_rate_at_time,
            'code'             => $this->code,
            'serial'           => $this->serial,
            'status'           => $this->status,
            'loaded_type'      => $this->loaded_type,
            'note'             => $this->note,
            'user'             => [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ],
            'card_type'        => [
                'id' => $this->cardType->id,
                'telco' => $this->cardType->telco,
            ],
            'created_at'       => $this->created_at,
        ];
    }
}
