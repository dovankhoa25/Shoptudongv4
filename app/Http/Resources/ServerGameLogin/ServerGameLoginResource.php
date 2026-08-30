<?php

namespace App\Http\Resources\ServerGameLogin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerGameLoginResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'ip'   => $this->ip,
            'port'   => $this->port,
            'name' => $this->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
