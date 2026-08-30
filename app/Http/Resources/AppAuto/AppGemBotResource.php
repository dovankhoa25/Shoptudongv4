<?php

namespace App\Http\Resources\AppAuto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppGemBotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'account_name'   => $this->account_name,
            'account_password'   => $this->account_password,
            'server_id'      => $this->server_id,
            'server_name'    => $this->server?->name, // nếu có quan hệ server
            'gem_qty'       => $this->gem_qty,
            'map_name'       => $this->map_name,
            'map_id'         => $this->map_id,
            'area_number'    => $this->area_number,
            'coordinates'    => $this->coordinates,
            'status'         => $this->status,
            'updated_by'     => $this->updated_by,
        ];
    }
}
