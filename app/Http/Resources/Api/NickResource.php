<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

class NickResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'price' => $this->price,
            'description' => $this->description,
            'image' => $this->image,
            'listing_type' => $this->listing_type,
            'attribute_cache_json' => $this->attribute_cache_json,
        ];
    }
}
