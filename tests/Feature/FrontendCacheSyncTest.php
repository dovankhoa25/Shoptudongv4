<?php
namespace Tests\Feature;

use App\Support\ApiCache;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class FrontendCacheSyncTest extends TestCase
{
    public function test_sync_is_signed_deduplicated_and_retries_failed_delivery(): void
    {
        Cache::flush();
        $secret = str_repeat('s', 32);
        config(['frontend_cache.origins' => ['https://store.example'], 'frontend_cache.secret' => $secret]);
        Http::fakeSequence()->push(['success' => true])->push(['success' => false], 503)->push(['success' => true]);
        $this->artisan('frontend-cache:sync')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->hasHeader('x-webhook-signature', 'sha256='.hash_hmac('sha256', $request->body(), $secret)));
        $this->artisan('frontend-cache:sync')->assertSuccessful();
        Http::assertSentCount(1);
        ApiCache::clearGroup('public:nick');
        $this->artisan('frontend-cache:sync')->assertFailed();
        $this->artisan('frontend-cache:sync')->assertSuccessful();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request['groups'] === ['nick']);
    }
}
