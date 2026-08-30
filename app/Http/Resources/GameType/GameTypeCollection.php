<?php

namespace App\Http\Resources\GameType;

use App\Http\Resources\Admin\Role\RoleResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class GameTypeCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'data' => $this->collection->map(fn($gametypes) => new GameTypeResource($gametypes)),
        ];
    }
}
