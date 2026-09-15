<?php

namespace Tests\Feature;

use App\Services\FrontendClientRegistry;
use App\Services\TrafficMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cors.static_allowed_origins' => ['https://shop.example'],
            'traffic_monitor.enabled' => true, 'traffic_monitor.store' => 'array']);
        Cache::flush();
        app(FrontendClientRegistry::class)->forget();
    }

    public function test_preflight_can_be_cached_without_running_the_chat_controller(): void
    {
        $this->withHeaders(['Origin' => 'https://shop.example',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/chat/realtime-channel?private-query=hidden')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Max-Age', '300');

        $snapshot = app(TrafficMonitor::class)->snapshot(5);
        $this->assertSame(1, $snapshot['totals']['options']);
        $this->assertSame(0, $snapshot['totals']['not_found']);
        $this->assertSame('OPTIONS /[preflight]', $snapshot['endpoints'][0]['key']);
        $this->assertStringNotContainsString('hidden', json_encode($snapshot));

        // A successful preflight does not authenticate the actual API call.
        $this->getJson('/api/chat/realtime-channel')->assertUnauthorized();
    }

    public function test_disallowed_and_removed_origins_get_no_cors_permission(): void
    {
        $headers = ['Origin' => 'https://other.example', 'Access-Control-Request-Method' => 'GET'];
        $this->withHeaders($headers)->options('/api/chat/realtime-channel')
            // With one configured origin Fruitcake emits that origin, never the caller's.
            ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example');
        config(['cors.static_allowed_origins' => []]);
        $this->withHeaders(['Origin' => 'https://shop.example'])->options('/api/chat/realtime-channel')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_retry_after_is_readable_on_a_cross_origin_429(): void
    {
        Route::get('/api/cors-limited-test', fn () => response()->json(['message' => 'Wait'], 429, ['Retry-After' => '42']));
        $this->withHeaders(['Origin' => 'https://shop.example'])->getJson('/api/cors-limited-test')
            ->assertStatus(429)->assertHeader('Retry-After', '42')
            ->assertHeader('Access-Control-Expose-Headers', 'Retry-After');
        $snapshot = app(TrafficMonitor::class)->snapshot(5);
        $this->assertSame(1, $snapshot['totals']['limited']);
        $this->assertSame(0, $snapshot['totals']['options']);
    }

    public function test_unknown_options_and_real_404_are_still_distinguished(): void
    {
        $this->options('/api/cors-missing-test')->assertNotFound();
        $this->getJson('/api/cors-missing-test')->assertNotFound();
        $snapshot = app(TrafficMonitor::class)->snapshot(5);
        $this->assertSame(2, $snapshot['totals']['not_found']);
        $this->assertSame(1, $snapshot['totals']['options']);
        $this->assertSame(['[unmatched]'], array_values(array_unique(array_column($snapshot['rows'], 'route'))));
    }
}
