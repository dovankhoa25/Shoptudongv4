<?php

namespace App\Http\Resources\Service;

use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'default_price' => $this->default_price,
            'original_price' => $this->original_price,
            'description' => $this->description,
            'status' => $this->status,
            'is_popular' => $this->is_popular,
            'processing_time' => $this->processing_time,
            'warranty' => $this->warranty,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
