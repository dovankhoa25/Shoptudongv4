<?php

namespace App\Http\Resources\GoldPrices;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoldPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'server_id'                => $this->server_id,
            'server_name'              => $this->whenLoaded('server', fn() => $this->server->name),
            'price'          => $this->price,
            'import_price'   => $this->import_price,
            'status'                   => $this->status,
            'created_at'               => $this->created_at?->toDateTimeString(),
            'updated_at'               => $this->updated_at?->toDateTimeString(),
        ];
    }
}
