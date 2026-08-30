<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiGemServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'name'   => $this->name,
            'name_view'   => $this->name_view,
            'status' => $this->status,
            'gem_prices' => ApiGemPriceResource::collection($this->whenLoaded('gemPrices')),
        ];
    }
}
