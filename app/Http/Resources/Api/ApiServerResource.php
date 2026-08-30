<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'name'   => $this->name,
            'name_view'   => $this->name_view,
            'status' => $this->status,
            'gold_prices' => ApiGoldPriceResource::collection($this->whenLoaded('goldPrices')),
        ];
    }
}
