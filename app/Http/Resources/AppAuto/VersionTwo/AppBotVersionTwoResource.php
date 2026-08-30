<?php

namespace App\Http\Resources\AppAuto\VersionTwo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppBotVersionTwoResource extends JsonResource
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
            'type'           => $this->type,
            'gold_bar_qty'   => (int) $this->gold_bar_qty,
            'gold_qty'       => (int)  $this->gold_qty,
            'map_name'       => $this->map_name,
            'map_id'         => (int) $this->map_id,
            'area_number'    => (int) $this->area_number,
            'coordinates'    => $this->coordinates,
            'proxy'    => $this->proxy,
            'status'         => $this->status,
            'updated_by'     => $this->updated_by,


            //  server
            'server_id'      => (int) $this->server_id,
            'server_game_id' => (int) $this->server_game_id ?? $this->server?->server_game_id,
            'server_name'    => $this->server?->name,

        ];
    }
}
