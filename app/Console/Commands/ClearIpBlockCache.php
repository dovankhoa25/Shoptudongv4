<?php

namespace App\Console\Commands;

use App\Services\AccessIpBlockCache;
use Illuminate\Console\Command;

class ClearIpBlockCache extends Command
{
    protected $signature = 'security:ip-block-cache:clear';

    protected $description = 'Invalidate only IP-block cache after manual SQL changes or cache recovery';

    public function handle(AccessIpBlockCache $cache): int
    {
        $cache->invalidate();
        $this->info('IP-block cache invalidated. Other application caches were not cleared.');

        return self::SUCCESS;
    }
}
