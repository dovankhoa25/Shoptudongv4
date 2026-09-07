<?php

namespace App\Http\Resources\Chat;

use App\Services\Chat\ChatSubjectResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $lastMessage = $this->relationLoaded('lastMessage') ? $this->lastMessage : null;
        $canSeeInternalNotes = $user
            && (int) $this->customer_id !== (int) $user->getKey();

        if ($lastMessage && $lastMessage->is_internal && (int) $this->customer_id === (int) $user?->getKey()) {
            $lastMessage = null;
        }

        if ($lastMessage && $this->relationLoaded('participants')) {
            $lastMessage->setRelation('conversation', $this->resource);
        }

        return [
            'id' => (int) $this->id,
            'category' => $this->category,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id === null ? null : (int) $this->subject_id,
            'subject' => app(ChatSubjectResolver::class)->summary(
                $this->subject_type,
                $this->relationLoaded('subject') ? $this->subject : null,
                $this->subject_id,
            ),
            'status' => $this->status,
            'priority' => $this->priority,
            'source_app' => $this->source_app,
            'source_url' => $this->source_url,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => (int) $this->customer->id,
                'username' => $this->customer->username,
                'display_name' => $this->customer->username,
                'avatar' => $this->customer->chat_avatar_url,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => (int) $this->assignee->id,
                'username' => $this->assignee->username,
                'display_name' => $this->assignee->chatDisplayName(),
                'avatar' => $this->assignee->chat_avatar_url,
            ] : null),
            'participants' => ChatParticipantResource::collection($this->whenLoaded('participants')),
            'last_message' => $lastMessage ? (new ChatMessageResource($lastMessage))->resolve($request) : null,
            'pinned_note' => $this->when(
                $canSeeInternalNotes && $this->relationLoaded('pinnedNote'),
                fn () => $this->pinnedNote
                    ? (new ChatMessageResource($this->pinnedNote))->resolve($request)
                    : null,
            ),
            'internal_notes_count' => $this->when(
                $canSeeInternalNotes && isset($this->internal_notes_count),
                fn () => (int) $this->internal_notes_count,
            ),
            'latest_message_id' => isset($this->latest_visible_message_id)
                ? (int) $this->latest_visible_message_id
                : ($lastMessage ? (int) $lastMessage->id : null),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => isset($this->unread_count)
                ? (int) $this->unread_count
                : ($user ? $this->unreadCountFor($user) : 0),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'permissions' => [
                'reply' => $user?->can('send', $this->resource) ?? false,
                'manage' => $user?->can('manage', $this->resource) ?? false,
                'assign' => $user?->can('assign', $this->resource) ?? false,
            ],
            'tipping' => $this->when(
                array_key_exists('tipping', $this->resource->getAttributes()),
                fn () => $this->resource->getAttribute('tipping'),
            ),
        ];
    }
}
