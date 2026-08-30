<?php

namespace App\Http\Resources\Category;

use App\Http\Resources\GameType\GameTypeResource;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'game_type_id' => $this->game_type_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image' => $this->image,
            'template' => $this->template,
            'is_public' => $this->is_public,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            // Relations
            'game_type' => new GameTypeResource($this->whenLoaded('gameType')),

            'image_url'  => $this->getFirstMediaUrl('image'),
        ];
    }
}
