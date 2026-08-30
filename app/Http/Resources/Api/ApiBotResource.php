<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiBotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // 'account_name'   => $this->account_name,
            'type' => $this->type,
            'server_id' => $this->server_id,
            // Giữ nguyên shape cho frontend public nhưng không lộ tồn kho thật.
            'gold_bar_qty' => 0,
            'gold_qty' => 0,
            'map_name' => $this->map_name,
            'map_id' => $this->map_id,
            'area_number' => $this->area_number,
            'status' => $this->status,
            // 'updated_by'     => $this->updated_by,
            // 'created_at'     => $this->created_at,
        ];
    }
}
