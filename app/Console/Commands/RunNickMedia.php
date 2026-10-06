<?php

namespace App\Console\Commands;

use App\Services\NickPublicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunNickMedia extends Command
{
    protected $signature = 'nick-media:run {--slot=1 : Independent worker slot, 1 to 3}';

    protected $description = 'Recover pending nick images and run a bounded Redis worker for hosting Cron';

    public function handle(NickPublicationService $service): int
    {
        $slot = (int) $this->option('slot');
        if ($slot < 1 || $slot > 3) {
            $this->error('slot must be between 1 and 3.');

            return self::FAILURE;
        }
        // Longer than the 50s work budget plus the final 90s job, released on normal exit.
        $lock = Cache::store('redis')->lock('nick-media:cron-worker:'.$slot, 240);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $service->recover();

            return $this->call('queue:work', [
                'connection' => 'nick-media', '--queue' => 'nick-media', '--stop-when-empty' => true,
                '--max-time' => 50, '--timeout' => 90, '--tries' => 1, '--sleep' => 1,
            ]);
        } finally {
            $lock->release();
        }
    }
}
