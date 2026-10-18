<?php

namespace Tests\Feature;

use App\Events\{TradingBotActivity, UserEvent};
use App\Jobs\PublishTradingBotActivities;
use App\Services\TradingV3\{TradingActivityBroadcast, TradingActivityDelivery, TradingActivityPublisher};
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\{Cache, Event, Queue};
use Tests\TestCase;

class TradingV3HostingActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); Event::fake([TradingBotActivity::class, UserEvent::class]);
    }

    private function activity(int $id = 1, string $code = 'ready'): array
    {
        return ['bot_type' => 'gem_bot', 'bot_id' => $id, 'server_id' => 6, 'code' => $code,
            'text' => 'Hosting activity', 'observed_at' => now()->toIso8601String(), 'expires_at' => now()->addSeconds(35)->toIso8601String()];
    }

    public function test_stateless_transport_never_touches_cache_bus_or_queue_even_with_obsolete_queue_settings(): void
    {
        config(['trading_v3.activity_dispatch' => 'queue', 'trading_v3.activity_cache_store' => 'redis', 'queue.default' => 'redis']);
        Cache::shouldReceive('store')->never(); Cache::shouldReceive('get')->never(); Cache::shouldReceive('put')->never();
        Queue::shouldReceive('connection')->never(); Queue::shouldReceive('push')->never();
        $bus = \Mockery::mock(Dispatcher::class); $bus->shouldNotReceive('dispatch'); $bus->shouldNotReceive('dispatchSync'); $bus->shouldNotReceive('dispatchAfterResponse');
        $this->app->instance(Dispatcher::class, $bus);
        $this->assertTrue(app(TradingActivityPublisher::class)->publish([$this->activity()]));
        Event::assertNotDispatched(TradingBotActivity::class);
        $this->app->terminate(); Event::assertDispatchedTimes(TradingBotActivity::class, 1);
    }

    public function test_real_termination_delivers_once_after_response_with_no_worker(): void
    {
        app('queue')->extend('redis', function () { throw new \RuntimeException('Redis OOM must not be reached'); });
        config(['queue.default' => 'redis']);
        $this->assertTrue(app(TradingActivityPublisher::class)->publish([$this->activity()]));
        Event::assertNotDispatched(TradingBotActivity::class);
        $this->app->terminate(); $this->app->terminate();
        Event::assertDispatchedTimes(TradingBotActivity::class, 1);
    }

    public function test_sync_global_queue_does_not_publish_activity_before_response(): void
    {
        config(['queue.default' => 'sync']);
        $this->assertTrue(app(TradingActivityPublisher::class)->publish([$this->activity()]));
        Event::assertNotDispatched(TradingBotActivity::class);
        $this->app->terminate(); Event::assertDispatchedTimes(TradingBotActivity::class, 1);
    }

    public function test_request_local_duplicate_frames_and_expired_activity_need_no_persistent_state(): void
    {
        $this->freezeTime(); $expired = $this->activity(2); $expired['expires_at'] = now()->subSecond()->toIso8601String();
        $this->assertTrue(app(TradingActivityPublisher::class)->publish([$this->activity(1, 'travelling'), $this->activity(1, 'ready'), $expired]));
        Event::assertNotDispatched(TradingBotActivity::class);
        $this->app->terminate(); Event::assertDispatchedTimes(TradingBotActivity::class, 1);
        Event::assertDispatched(TradingBotActivity::class, fn($event) => $event->activity['code'] === 'ready');
    }

    public function test_activity_that_expires_after_response_is_not_sent(): void
    {
        $this->freezeTime(); $notice = ['user_id' => 7, 'message' => 'Private', 'payload' => ['expires_at' => now()->addSeconds(35)->toIso8601String()]];
        $this->assertTrue(app(TradingActivityPublisher::class)->publish([$this->activity()], [$notice]));
        $this->travel(36)->seconds(); $this->app->terminate();
        Event::assertNotDispatched(TradingBotActivity::class); Event::assertNotDispatched(UserEvent::class);
    }

    public function test_obsolete_queued_activity_jobs_are_noops_without_replaying_notices(): void
    {
        Cache::shouldReceive('store')->never();
        $transport = \Mockery::mock(TradingActivityBroadcast::class); $transport->shouldNotReceive('publishPublic'); $transport->shouldNotReceive('publishPrivate');
        $this->app->instance(TradingActivityBroadcast::class, $transport);
        $legacy = new PublishTradingBotActivities([['key' => 'old-key', 'token' => 'old-token', 'activity' => $this->activity()]], [['message' => 'obsolete private notice']]);
        $restored = unserialize(serialize($legacy)); $restored->handle();
        Event::assertNotDispatched(TradingBotActivity::class); Event::assertNotDispatched(UserEvent::class);
    }

    public function test_public_and_private_network_failures_stay_isolated_after_response(): void
    {
        $transport = new class extends TradingActivityBroadcast {
            public int $publicCalls = 0; public int $privateCalls = 0;
            public function publishPublic(array $activities): void { $this->publicCalls++; throw new \RuntimeException('Ably unavailable'); }
            public function publishPrivate(array $notice): void { $this->privateCalls++; throw new \RuntimeException('Ably unavailable'); }
        };
        $this->app->instance(TradingActivityBroadcast::class, $transport);
        $notice = ['user_id' => 7, 'message' => 'Private', 'payload' => ['expires_at' => now()->addSeconds(35)->toIso8601String()]];
        $this->assertTrue(app(TradingActivityPublisher::class)->publish([$this->activity()], [$notice]));
        $this->assertSame(0, $transport->publicCalls); $this->assertSame(0, $transport->privateCalls);
        $this->app->terminate(); $this->assertSame(1, $transport->publicCalls); $this->assertSame(1, $transport->privateCalls);
        Event::assertNotDispatched(TradingBotActivity::class); Event::assertNotDispatched(UserEvent::class);
    }

    public function test_public_chunk_budget_bounds_slow_stateless_delivery(): void
    {
        $this->freezeTime(); $elapsed = (object) ['seconds' => 0.0];
        $transport = new class($elapsed) extends TradingActivityBroadcast {
            public int $calls = 0; public int $privateCalls = 0;
            public function __construct(private object $elapsed) {}
            public function publishPublic(array $activities): void { $this->calls++; if (count($activities) > 100) throw new \LogicException('Unbounded chunk'); $this->elapsed->seconds += 4; }
            public function publishPrivate(array $notice): void { $this->privateCalls++; }
        };
        $this->app->instance(TradingActivityBroadcast::class, $transport);
        $activities = []; for ($id = 1; $id <= 500; $id++) $activities[] = $this->activity($id);
        $notice = ['payload' => ['expires_at' => now()->addSeconds(35)->toIso8601String()]];
        $delivery = new class($elapsed) extends TradingActivityDelivery {
            public function __construct(private object $elapsed) {}
            protected function monotonicTime(): float { return $this->elapsed->seconds; }
        };
        $delivery->deliver($activities, [$notice]);
        $this->assertSame(3, $transport->calls); $this->assertSame(0, $transport->privateCalls); $this->assertSame(12.0, $elapsed->seconds);
    }
}
