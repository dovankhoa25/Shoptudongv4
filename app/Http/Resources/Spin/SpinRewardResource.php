<?php

namespace App\Http\Resources\Spin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpinRewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'spin_id' => $this->spin_id,
            'spin' => $this->whenLoaded('spin', function () {
                return [
                    'id' => $this->spin->id,
                    'name' => $this->spin->name,
                ];
            }),
            'reward_type' => $this->reward_type,
            'reward_type_label' => $this->getRewardTypeLabel(),
            'reward_value' => $this->reward_value,
            'reward_display' => $this->getRewardDisplay(),
            'image' => $this->image,
            'image_url' => $this->image_url, // ✅ Dùng accessor từ Model
            'probability' => (float) $this->probability, // ✅ Cast to float
            'probability_formatted' => number_format($this->probability, 2) . '%',
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),
        ];
    }

    private function getRewardTypeLabel(): string
    {
        return match ($this->reward_type) {
            'text' => 'Văn bản',
            'coin' => 'Xu',
            'gem' => 'Kim cương',
            'nick' => 'Nick game',
            'item' => 'Vật phẩm',
            default => $this->reward_type,
        };
    }

    private function getRewardDisplay(): string
    {
        return match ($this->reward_type) {
            'coin' => number_format((float) $this->reward_value, 0, ',', '.') . ' xu',
            'gem' => number_format((float) $this->reward_value, 0, ',', '.') . ' kim cương',
            default => $this->reward_value,
        };
    }
}
