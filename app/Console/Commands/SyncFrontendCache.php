<?php
namespace App\Console\Commands;

use App\Support\ApiCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SyncFrontendCache extends Command
{
    protected $signature = 'frontend-cache:sync';
    protected $description = 'Notify configured storefronts when committed public cache generations change';

    public function handle(): int
    {
        $origins = config('frontend_cache.origins', []);
        if (!$origins) return self::SUCCESS;
        $secret = (string) config('frontend_cache.secret');
        if (strlen($secret) < 32) {
            $this->error('FRONTEND_CACHE_WEBHOOK_SECRET must contain at least 32 characters.');
            return self::FAILURE;
        }
        $lock = Cache::lock('frontend-cache:sync:lock', 120);
        if (!$lock->get()) return self::SUCCESS;
        try {
            $versions = [];
            foreach ([
                'catalog' => ['public:catalog'],
                'nick' => ['public:nick'],
                'prices' => ['public:servers', 'public:server-prices'],
            ] as $name => $groups) {
                $versions[$name] = hash('sha256', json_encode(array_map(
                    fn ($group) => Cache::get(ApiCache::key('generation', $group)), $groups
                )));
            }
            $failed = false;
            foreach ($origins as $origin) {
                $parts = parse_url($origin);
                if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                    || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
                    || !in_array($parts['path'] ?? '', ['', '/'], true)) {
                    $this->error('Invalid frontend cache origin in configuration.');
                    $failed = true;
                    continue;
                }
                $url = rtrim($origin, '/').'/api/webhooks/invalidate';
                $key = 'frontend-cache:ack:'.hash('sha256', $url);
                $previous = Cache::get($key, []);
                $changed = array_keys(array_filter($versions, fn ($version, $name) => ($previous[$name] ?? null) !== $version, ARRAY_FILTER_USE_BOTH));
                if (!$changed) continue;
                $body = json_encode(['type' => 'groups', 'groups' => $changed, 'timestamp' => now()->timestamp], JSON_THROW_ON_ERROR);
                try {
                    $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()
                        ->withHeaders(['x-webhook-signature' => 'sha256='.hash_hmac('sha256', $body, $secret)])
                        ->withBody($body, 'application/json')->post($url);
                    if (!$response->successful() || $response->json('success') !== true) {
                        $failed = true;
                        $this->warn('Frontend cache sync failed for '.$parts['host'].' (HTTP '.$response->status().').');
                        continue;
                    }
                    // A write during the HTTP request changes the next fingerprint and is sent next run.
                    Cache::forever($key, $versions);
                    $this->info('Frontend cache synced: '.$parts['host']);
                } catch (\Throwable) {
                    $failed = true;
                    $this->warn('Frontend cache sync connection failed for '.$parts['host'].'.');
                }
            }
            return $failed ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
