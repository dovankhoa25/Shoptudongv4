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
            'status'                    => $this->status,
            'created_at'                => $this->created_at,
        ];
    }
}
