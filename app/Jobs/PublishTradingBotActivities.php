<?php

namespace App\Jobs;

use App\Services\TradingV3\TradingActivityBroadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\{Cache, Log};
use Illuminate\Support\Carbon;

class PublishTradingBotActivities implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public array $frames, public array $notices = []) {}

    public function handle(): void
    {
        $cache = Cache::store(config('trading_v3.activity_cache_store', 'redis'));
        $activities = [];
        foreach ($this->frames as $frame) {
            try {
                if (!Carbon::parse($frame['activity']['expires_at'])->isFuture() || $cache->get($frame['key']) !== $frame['token']) continue;
                $activities[] = $frame['activity'];
            } catch (\Throwable $e) {
                // The next current frame replaces failures; stale realtime frames are never retried.
                Log::warning('Trading activity broadcast failed', ['bot_id' => $frame['activity']['bot_id']]);
            }
        }
        $broadcast = app(TradingActivityBroadcast::class);
        try { $broadcast->publishPublic($activities); }
        catch (\Throwable $e) { Log::warning('Trading activity batch broadcast failed', ['count' => count($activities)]); }
        foreach ($this->notices as $notice) {
            try {
                if (!Carbon::parse($notice['payload']['expires_at'])->isFuture()) continue;
                $broadcast->publishPrivate($notice);
            } catch (\Throwable $e) {
                Log::warning('Trading private activity broadcast failed');
            }
        }
    }
}
