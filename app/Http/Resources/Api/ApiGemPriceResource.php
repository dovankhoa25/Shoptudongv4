<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiGemPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'server_id'           => $this->server_id,
            'multiplier'    => $this->multiplier,
            'gift_ticket_multiplier' => $this->gift_ticket_multiplier,
            'gift_ticket_gems_per_10k' => (float) $this->resource->multiplierFor('gift_ticket') * 10,
            'gift_ticket_quote_per_10k' => $this->resource->quoteOrder(10000, 'gift_ticket'),
            'consignment_quote_per_10k' => $this->resource->quoteOrder(10000, 'consignment'),
            'min_amount' => (int) ($this->min_amount ?? 10000),
            'status'                    => $this->status,
            'created_at'                => $this->created_at,
        ];
    }
}
