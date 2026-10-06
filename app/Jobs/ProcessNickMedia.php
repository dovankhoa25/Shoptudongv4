<?php

namespace App\Jobs;

use App\Services\NickPublicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessNickMedia implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $fileId)
    {
        $this->onConnection('nick-media')->onQueue('nick-media');
    }

    public function handle(NickPublicationService $service): void
    {
        $service->processFile($this->fileId);
    }
}
