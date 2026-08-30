<?php

namespace App\Http\Resources\AppAuto\VersionTwo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppServerGameLoginVersionTwoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'ip'   => $this->ip,
            'port'   => $this->port,
            'name'   => $this->name,
        ];
    }
}
