<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatTipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'uuid' => $this->uuid,
            'conversation_id' => (int) $this->conversation_id,
            'message_id' => $this->message_id === null ? null : (int) $this->message_id,
            'payer' => $this->whenLoaded('payer', fn () => $this->payer ? [
                'id' => (int) $this->payer->id,
                'username' => $this->payer->username,
                'display_name' => $this->payer->username,
                'avatar' => $this->payer->chat_avatar_url,
            ] : null),
            'recipient' => $this->whenLoaded('recipient', fn () => $this->recipient ? [
                'id' => (int) $this->recipient->id,
                'username' => $this->recipient->username,
                'display_name' => $this->recipient->chatDisplayName(),
                'avatar' => $this->recipient->chat_avatar_url,
            ] : null),
            'amount' => (int) $this->amount,
            'platform_fee' => (int) $this->platform_fee,
            'recipient_amount' => (int) $this->recipient_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'note' => $this->note,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
