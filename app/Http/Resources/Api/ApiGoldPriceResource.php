<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiGoldPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'price'           => $this->price,
            'import_price'    => $this->import_price,
            'status'                    => $this->status,
            'created_at'                => $this->created_at,
        ];
    }
}
