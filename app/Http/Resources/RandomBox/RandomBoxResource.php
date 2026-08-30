<?php

namespace App\Http\Resources\RandomBox;

use App\Http\Resources\Category\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RandomBoxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'price_formatted' => number_format($this->price) . 'đ',
            'image_url' =>  $this->getFirstMediaUrl('image'),
            // 'image_url' => $this->image ? asset('storage/' . $this->image) : null,
            'is_public' => $this->is_public,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'created_at_formatted' => $this->created_at->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at,

            // Category relationship
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),

            // Additional computed fields
            'total_nicks' => $this->whenCounted('randomNicks'),
            'available_nicks' => $this->whenCounted('availableNicks'),
            'status_text' => $this->is_public ? 'Công khai' : 'Đã ẩn',
            'status_color' => $this->is_public ? 'success' : 'danger',
        ];
    }
}
