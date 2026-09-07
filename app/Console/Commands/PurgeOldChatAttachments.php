<?php

namespace App\Console\Commands;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PurgeOldChatAttachments extends Command
{
    protected $signature = 'chat:purge-attachments
        {--days= : Override CHAT_MEDIA_RETENTION_DAYS}
        {--dry-run : Count matching files without deleting them}';

    protected $description = 'Delete private image attachments from old completed chats and remove orphaned chat media';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('chat.attachments.retention_days', 90);

        if ($days < 1) {
            $this->error('Retention days must be at least 1.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $messageCount = 0;
        $fileCount = 0;

        ChatMessage::query()
            ->withTrashed()
            ->whereHas('conversation', fn ($query) => $query
                ->whereIn('status', [
                    ChatConversation::STATUS_RESOLVED,
                    ChatConversation::STATUS_CLOSED,
                ])
                ->where(fn ($closedAt) => $closedAt
                    ->where('resolved_at', '<=', $cutoff)
                    ->orWhere(fn ($legacy) => $legacy
                        ->whereNull('resolved_at')
                        ->where('updated_at', '<=', $cutoff))))
            ->whereHas('media', fn ($query) => $query
                ->where('collection_name', ChatMessage::MEDIA_COLLECTION_IMAGES))
            ->with(['media' => fn ($query) => $query
                ->where('collection_name', ChatMessage::MEDIA_COLLECTION_IMAGES)])
            ->chunkById(100, function ($messages) use ($dryRun, &$messageCount, &$fileCount): void {
                foreach ($messages as $message) {
                    $count = $message->media->count();
                    if ($count === 0) {
                        continue;
                    }

                    $messageCount++;
                    $fileCount += $count;

                    if ($dryRun) {
                        continue;
                    }

                    $metadata = $message->metadata ?? [];
                    $metadata['attachments_count'] = max((int) ($metadata['attachments_count'] ?? 0), $count);
                    $metadata['attachments_purged_at'] = now()->toIso8601String();
                    $message->forceFill(['metadata' => $metadata])->save();
                    $message->clearMediaCollection(ChatMessage::MEDIA_COLLECTION_IMAGES);
                }
            });

        $verb = $dryRun ? 'Would purge' : 'Purged';
        $this->info("{$verb} {$fileCount} attachment(s) from {$messageCount} message(s).");

        // Database-level cascades do not fire Media Library model observers.
        // Sweep media whose chat message no longer exists so both the row and
        // its private file cannot be left behind indefinitely.
        $orphanCount = 0;
        $chatMessageMorphTypes = array_values(array_unique([
            ChatMessage::class,
            (new ChatMessage)->getMorphClass(),
        ]));
        Media::query()
            ->whereIn('model_type', $chatMessageMorphTypes)
            ->whereNotIn('model_id', ChatMessage::query()->withTrashed()->select('id'))
            ->chunkById(100, function ($mediaItems) use ($dryRun, &$orphanCount): void {
                foreach ($mediaItems as $media) {
                    $orphanCount++;
                    if (! $dryRun) {
                        $media->delete();
                    }
                }
            });
        $this->info("{$verb} {$orphanCount} orphaned chat attachment(s).");

        return self::SUCCESS;
    }
}
