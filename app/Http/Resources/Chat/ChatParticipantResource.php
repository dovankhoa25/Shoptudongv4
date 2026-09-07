<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatParticipantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'role' => $this->role,
            'last_read_message_id' => $this->last_read_message_id === null ? null : (int) $this->last_read_message_id,
            'last_read_at' => $this->last_read_at?->toIso8601String(),
            'joined_at' => $this->joined_at?->toIso8601String(),
            'left_at' => $this->left_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => (int) $this->user->id,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar_url,
            ] : null),
        ];
    }
}
