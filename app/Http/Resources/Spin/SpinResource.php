<?php

namespace App\Http\Resources\Spin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ];
            }),
            'name' => $this->name,
            'image' => $this->image,
            'image_url' => $this->image_url, // ✅ FIXED - dùng accessor
            'type' => $this->type,
            'type_label' => $this->type === 'wheel' ? 'Vòng quay' : 'Lật xu',
            'price_per_turn' => (float) $this->price_per_turn, // ✅ Cast to float
            'price_per_turn_formatted' => number_format($this->price_per_turn, 0, ',', '.') . ' VNĐ',
            'total_slots' => (int) $this->total_slots, // ✅ Cast to int
            'is_public' => (bool) $this->is_public, // ✅ Cast to bool
            'sort_order' => (int) $this->sort_order,
            'description' => $this->description,
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),

            // Thống kê
            'rewards_count' => $this->whenCounted('rewards'),
            'results_count' => $this->whenCounted('results'),
            'tickets_count' => $this->whenCounted('tickets'),

            // Relations
            'rewards' => SpinRewardResource::collection($this->whenLoaded('rewards')),
            'recent_results' => SpinResultResource::collection($this->whenLoaded('results')),
        ];
    }
}
