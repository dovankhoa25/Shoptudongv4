<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Deserialization compatibility for obsolete, transient activity jobs only. */
class PublishTradingBotActivities implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public array $frames, public array $notices = []) {}

    // New activity is stateless. Never replay an old queue's cached frames/notices.
    public function handle(): void {}
}
