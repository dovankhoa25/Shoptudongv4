<?php

namespace App\Http\Resources\Api;


use Illuminate\Http\Resources\Json\JsonResource;

class ApiCategoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'game_type_id' => $this->game_type_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image' => $this->getFirstMediaUrl('image'),
            'template' => $this->template,
            // 'is_public' => $this->is_public,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'serverCount' => rand(500, 1500),
            'historyCount' => rand(500, 1200),
            'isHot' => true,
            // 'created_at' => $this->created_at,
            // 'updated_at' => $this->updated_at,
        ];
    }
}
