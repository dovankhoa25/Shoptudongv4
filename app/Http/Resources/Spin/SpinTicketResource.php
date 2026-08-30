<?php

namespace App\Http\Resources\Spin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpinTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
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
                    'image_url' => $this->spin->image_url,
                ];
            }),
            'turns_remaining' => (int) $this->turns_remaining,
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
