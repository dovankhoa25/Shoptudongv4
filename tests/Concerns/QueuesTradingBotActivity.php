<?php

namespace Tests\Concerns;

use App\Jobs\PublishTradingBotActivities;
use Illuminate\Support\Facades\Queue;

trait QueuesTradingBotActivity
{
    protected function fakeTradingActivityQueue(): void
    {
        config(['trading_v3.activity_cache_store' => 'array', 'trading_v3.activity_queue_connection' => 'redis']);
        Queue::fake([PublishTradingBotActivities::class]);
    }

    /** Explicitly act as the queue worker after the HTTP response has returned. */
    protected function runQueuedTradingActivities(): void
    {
        Queue::pushed(PublishTradingBotActivities::class)->each(fn($job) => $job->handle());
        Queue::fake([PublishTradingBotActivities::class]);
    }
}
