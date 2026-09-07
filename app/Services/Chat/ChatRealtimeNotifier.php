<?php

namespace App\Services\Chat;

use App\Enums\Permission;
use App\Events\ChatInboxUpdated;
use App\Events\ChatMessageReactionUpdated;
use App\Events\ChatMessageSent;
use App\Events\ChatReadUpdated;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatRealtimeNotifier
{
    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $conversationSummary
     */
    public function message(
        ChatConversation $conversation,
        array $message,
        bool $internal,
        array $conversationSummary = [],
    ): void {
        $this->send(new ChatMessageSent(
            (int) $conversation->getKey(),
            $message,
            $this->recipientIds($conversation, $internal),
            conversation: $conversationSummary,
        ));
    }

    /** @param array<string, mixed> $reader */
    public function read(
        ChatConversation $conversation,
        array $reader,
        int $lastReadMessageId,
        string $readAt,
    ): void {
        $this->send(new ChatReadUpdated(
            (int) $conversation->getKey(),
            $reader,
            $lastReadMessageId,
            $readAt,
            $this->recipientIds($conversation),
        ));
    }

    /** @param list<array{emoji: string, count: int, user_ids: list<int>}> $reactions */
    public function reaction(
        ChatConversation $conversation,
        ChatMessage $message,
        array $reactions,
        int $actorId,
        bool $active,
    ): void {
        $this->send(new ChatMessageReactionUpdated(
            (int) $conversation->getKey(),
            (int) $message->getKey(),
            $reactions,
            $actorId,
            $active,
            $this->recipientIds($conversation, (bool) $message->is_internal),
        ));
    }

    /**
     * @param  array<string, mixed>  $conversation
     * @param  list<int>  $additionalRecipientIds
     */
    public function inbox(
        string $action,
        ChatConversation $chat,
        array $conversation,
        array $additionalRecipientIds = [],
        bool $internal = false,
    ): void {
        $this->send(new ChatInboxUpdated(
            $action,
            $conversation,
            $this->recipientIds($chat, $internal, $additionalRecipientIds),
        ));
    }

    /** @param list<int> $additionalRecipientIds
     * @return list<int>
     */
    private function recipientIds(
        ChatConversation $conversation,
        bool $internal = false,
        array $additionalRecipientIds = [],
    ): array {
        $conversation = $conversation->fresh() ?? $conversation;
        $recipientIds = collect();

        if (! $internal) {
            $customer = User::query()->find($conversation->customer_id);
            if ($customer && ! $customer->isLocked()) {
                $recipientIds->push((int) $customer->getKey());
            }
        }

        $candidateIds = collect();

        if ($conversation->assigned_to_id) {
            $candidateIds->push((int) $conversation->assigned_to_id);
        }

        if (! $conversation->assigned_to_id && $conversation->subject_id) {
            $conversation->loadMissing('subject');
            $subjectOwnerId = match ($conversation->subject_type) {
                'service_order' => $conversation->subject?->getAttribute('receiver_id'),
                'nick_order' => $conversation->subject?->getAttribute('seller_id'),
                default => null,
            };

            if ($subjectOwnerId) {
                $candidateIds->push((int) $subjectOwnerId);
            }
        }

        $candidateIds->push(...$conversation->participants()
            ->where('role', ChatParticipant::ROLE_AGENT)
            ->whereNull('left_at')
            ->pluck('user_id')
            ->all());

        User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where(function ($users) use ($candidateIds): void {
                $users
                    ->whereIn('id', $candidateIds->unique()->filter()->values())
                    ->orWhereHas('roles', fn ($roles) => $roles
                        ->whereIn('name', ['admin', 'super-admin']))
                    ->orWhereHas('permissions', fn ($permissions) => $permissions
                        ->where('name', Permission::ChatsViewAll->value)
                        ->where('guard_name', 'web'))
                    ->orWhereHas('roles.permissions', fn ($permissions) => $permissions
                        ->where('name', Permission::ChatsViewAll->value)
                        ->where('guard_name', 'web'));
            })
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(fn (User $user): bool => (! $internal || (int) $user->getKey() !== (int) $conversation->customer_id)
                && $user->can('view', $conversation))
            ->each(fn (User $user) => $recipientIds->push((int) $user->getKey()));

        if ($additionalRecipientIds !== []) {
            User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereIn('id', array_unique($additionalRecipientIds))
                ->get()
                ->filter(fn (User $user): bool => ! $user->canViewAllChats()
                    && $user->isChatAgent()
                    && $user->can('chats.view'))
                ->each(fn (User $user) => $recipientIds->push((int) $user->getKey()));
        }

        return $recipientIds->unique()->values()->all();
    }

    private function send(object $event): void
    {
        try {
            // Laravel Echo's Ably connector uses the Pusher-compatible socket ID.
            // Passing that value to the native Ably broadcaster as connectionKey
            // makes Ably reject the entire publish request. Broadcast to every
            // connection instead; clients de-duplicate messages by database ID.
            broadcast($event);
        } catch (Throwable $exception) {
            Log::warning('Chat realtime broadcast failed', [
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
