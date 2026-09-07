<?php

namespace App\Http\Resources\Chat;

use App\Models\ChatParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $conversation = $this->relationLoaded('conversation') ? $this->conversation : null;
        $seenBy = collect();

        if ($conversation?->relationLoaded('participants')) {
            $seenBy = $conversation->participants
                ->filter(fn (ChatParticipant $participant): bool => $participant->role === ChatParticipant::ROLE_AGENT
                    && $participant->last_read_message_id !== null
                    && (int) $participant->last_read_message_id >= (int) $this->id)
                ->map(fn (ChatParticipant $participant): array => [
                    'id' => (int) $participant->user_id,
                    'username' => $participant->user?->username,
                    'avatar' => $participant->user?->avatar_url,
                    'read_at' => $participant->last_read_at?->toIso8601String(),
                ])
                ->values();
        }

        return [
            'id' => (int) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'sender_kind' => $this->sender_kind,
            'type' => $this->type,
            'body' => $this->body,
            'reply_to_id' => $this->reply_to_id === null ? null : (int) $this->reply_to_id,
            'client_message_id' => $this->client_message_id,
            'metadata' => $this->metadata,
            'is_internal' => (bool) $this->is_internal,
            'is_mine' => (int) $this->sender_id === (int) $request->user()?->getKey(),
            'sender' => $this->whenLoaded('sender', fn () => $this->sender ? [
                'id' => (int) $this->sender->id,
                'username' => $this->sender->username,
                'avatar' => $this->sender->avatar_url,
            ] : null),
            'seen_by' => $seenBy,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
