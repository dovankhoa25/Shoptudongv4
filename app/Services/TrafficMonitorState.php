<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class TrafficMonitorState
{
    public function __construct(private Filesystem $files) {}

    public function enabled(): bool
    {
        $path = (string) config('traffic_monitor.state_path');
        // A tiny local control file avoids a Redis/DB lookup just to disable metrics.
        // Clear PHP's stat cache so long-lived workers see changes on the next request.
        clearstatcache(true, $path);
        if (!is_file($path)) return (bool) config('traffic_monitor.enabled', false);

        // A broken/unreadable switch must never enable request recording.
        return @file_get_contents($path) === '1';
    }

    public function setEnabled(bool $enabled): void
    {
        $path = (string) config('traffic_monitor.state_path');
        $this->files->ensureDirectoryExists(dirname($path), 0750);
        $this->files->replace($path, $enabled ? '1' : '0', 0640);
        if ($this->enabled() !== $enabled) {
            throw new RuntimeException('Could not persist the traffic monitor switch.');
        }
    }
}
