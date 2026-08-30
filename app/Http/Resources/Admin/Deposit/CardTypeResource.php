<?php

namespace App\Http\Resources\Admin\Deposit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telco' => $this->telco,
            'discount_rate' => (float) $this->discount_rate,
            'status' => (bool) $this->status,
            'cards_count' => (int) ($this->cards_count ?? 0),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
