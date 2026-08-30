<?php

namespace App\Http\Resources\Spin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpinResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'avatar' => $this->user->avatar ?? null,
                ];
            }),
            'spin_id' => $this->spin_id,
            'spin' => $this->whenLoaded('spin', function () {
                return [
                    'id' => $this->spin->id,
                    'name' => $this->spin->name,
                    'type' => $this->spin->type,
                ];
            }),
            'reward_type' => $this->reward_type,
            'reward_type_label' => $this->getRewardTypeLabel(),
            'reward_value' => $this->reward_value,
            'reward_display' => $this->getRewardDisplay(),
            'reward_id' => $this->reward_id,
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'created_at_human' => $this->created_at?->diffForHumans(),
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
            'coin' => number_format($this->reward_value, 0, ',', '.') . ' xu',
            'gem' => number_format($this->reward_value, 0, ',', '.') . ' kim cương',
            default => $this->reward_value,
        };
    }
}
