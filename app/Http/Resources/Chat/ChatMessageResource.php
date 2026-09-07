<?php

namespace App\Http\Resources\Chat;

use App\Models\ChatParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
                    'display_name' => $participant->user?->chatDisplayName(),
                    'avatar' => $participant->user?->chat_avatar_url,
                    'read_at' => $participant->last_read_at?->toIso8601String(),
                ])
                ->values();
        }

        $urlExpiresAt = now()->addMinutes(max(1, (int) config('chat.attachments.signed_url_minutes', 60)));
        $attachments = $this->getMedia($this->resource::MEDIA_COLLECTION_IMAGES)
            ->map(function (Media $media) use ($urlExpiresAt): array {
                $url = URL::temporarySignedRoute(
                    'chat.media.show',
                    $urlExpiresAt,
                    ['media' => $media->uuid],
                );

                return [
                    'id' => (int) $media->id,
                    'uuid' => $media->uuid,
                    'url' => $url,
                    // A dedicated thumbnail conversion can be introduced later;
                    // the signed original keeps the initial implementation robust
                    // across local and object-storage disks.
                    'thumbnail_url' => $url,
                    'name' => $media->name,
                    'mime_type' => $media->mime_type,
                    'size' => (int) $media->size,
                    'width' => $media->getCustomProperty('width'),
                    'height' => $media->getCustomProperty('height'),
                ];
            })
            ->values();
        $metadata = $this->metadata ?? [];

        return [
            'id' => (int) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'sender_kind' => $this->sender_kind,
            'type' => $this->type,
            'body' => $this->body ?? '',
            'reply_to_id' => $this->reply_to_id === null ? null : (int) $this->reply_to_id,
            'client_message_id' => $this->client_message_id,
            'metadata' => $metadata,
            'is_internal' => (bool) $this->is_internal,
            'is_mine' => (int) $this->sender_id === (int) $request->user()?->getKey(),
            'sender' => $this->whenLoaded('sender', fn () => $this->sender ? [
                'id' => (int) $this->sender->id,
                'username' => $this->sender->username,
                'display_name' => $this->sender_kind === $this->resource::SENDER_AGENT
                    ? $this->sender->chatDisplayName()
                    : $this->sender->username,
                'avatar' => $this->sender->chat_avatar_url,
            ] : null),
            'seen_by' => $seenBy,
            'attachments' => $attachments,
            'attachments_expired' => isset($metadata['attachments_purged_at']),
            'reactions' => $this->is_internal
                ? []
                : $this->resource->reactionSummary(
                    $request->user() ? (int) $request->user()->getKey() : null,
                ),
            'tip' => $this->whenLoaded(
                'tip',
                fn () => $this->tip
                    ? (new ChatTipResource($this->tip))->resolve($request)
                    : null,
            ),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
