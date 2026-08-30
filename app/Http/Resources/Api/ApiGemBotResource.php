<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiGemBotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            // 'account_name'   => $this->account_name,
            'server_id'      => $this->server_id,
            // 'gem_qty'       => $this->gem_qty,
            'map_name'       => $this->map_name,
            'map_id'         => $this->map_id,
            'area_number'    => $this->area_number,
            'coordinates'    => $this->coordinates ?? '',
            'status'         => $this->status,
            // 'updated_by'     => $this->updated_by,
            // 'created_at'     => $this->created_at,
        ];
    }
}
