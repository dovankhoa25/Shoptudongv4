<?php

namespace App\Http\Resources\AppAuto\VersionTwo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppServerVersionTwoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'name'   => $this->name,
            'name_view'   => $this->name_view,
            'server_game_id'   => (int) $this->server_game_id,
            'ip'   => $this->ip,
            'port'   => $this->port,
            'status' => $this->status,
        ];
    }
}
