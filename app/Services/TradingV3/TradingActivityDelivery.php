<?php

namespace App\Services\TradingV3;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Stateless best-effort Ably delivery, invoked only after the HTTP response. */
class TradingActivityDelivery
{
    private const BUDGET_SECONDS = 12;

    public function deliver(array $activities, array $notices = []): void
    {
        try { $this->send($activities, $notices); }
        catch (\Throwable $e) { $this->warn('Trading activity transport failed'); }
    }

    private function send(array $activities, array $notices): void
    {
        $started = $this->monotonicTime(); $current = [];
        foreach ($activities as $activity) {
            if ($this->monotonicTime() - $started >= self::BUDGET_SECONDS) break;
            try {
                if (!Carbon::parse($activity['expires_at'])->isFuture()) continue;
                // Duplicate frames in one HTTP payload need no cross-request state.
                $current[$activity['bot_type'].':'.$activity['bot_id']] = $activity;
            } catch (\Throwable $e) { $this->warn('Trading activity frame was ignored'); }
        }
        $broadcast = app(TradingActivityBroadcast::class); $chunks = array_chunk(array_values($current), 100);
        if (count($chunks) > 1) {
            $offset = ((int) floor(microtime(true) / 15)) % count($chunks);
            $chunks = [...array_slice($chunks, $offset), ...array_slice($chunks, 0, $offset)];
        }
        foreach ($chunks as $chunk) {
            if ($this->monotonicTime() - $started >= self::BUDGET_SECONDS) break;
            try { $broadcast->publishPublic($chunk); }
            catch (\Throwable $e) { $this->warn('Trading activity batch broadcast failed'); }
        }
        foreach ($notices as $notice) {
            if ($this->monotonicTime() - $started >= self::BUDGET_SECONDS) break;
            try {
                if (!Carbon::parse($notice['payload']['expires_at'])->isFuture()) continue;
                $broadcast->publishPrivate($notice);
            } catch (\Throwable $e) { $this->warn('Trading private activity broadcast failed'); }
        }
    }

    protected function monotonicTime(): float { return hrtime(true) / 1_000_000_000; }

    private function warn(string $message): void
    {
        try { Log::warning($message); } catch (\Throwable $ignored) { }
    }
}
