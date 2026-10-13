<?php

namespace Tests\Feature;

use App\Events\{TradingBotActivity, UserEvent};
use App\Jobs\PublishTradingBotActivities;
use App\Models\{Bot, GemBot, GemTransaction, Server, User};
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event, Queue};
use Illuminate\Support\Str;
use Tests\Concerns\QueuesTradingBotActivity;
use Tests\TestCase;

class TradingV3ActivityQueueTest extends TestCase
{
    use RefreshDatabase, QueuesTradingBotActivity;

    private string $worker = '00000000-0000-4000-8000-000000000401';
    private string $secret = 'test-only-activity-queue-secret-at-least-32';
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config(['trading_v3.enabled' => true, 'trading_v3.hmac_secret' => $this->secret]);
        $this->fakeTradingActivityQueue(); Event::fake(); $this->freezeTime();
        $this->server = Server::create(['name' => 'activity-queue-test', 'status' => true]);
    }

    private function bot(string $type = 'gem_bot'): Bot|GemBot
    {
        $data = ['name' => 'queue-test', 'account_name' => 'dummy-'.Str::uuid(), 'account_password' => 'dummy-password',
            'server_id' => $this->server->id, 'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41',
            'coordinates' => '652-336', 'status' => true];
        $bot = $type === 'gem_bot' ? GemBot::create([...$data, 'gem_qty' => 100, 'gift_ticket_qty' => 10])
            : Bot::create([...$data, 'type' => 'selling_main', 'gold_qty' => 0, 'gold_bar_qty' => 10]);
        $bot->forceFill(['v3_worker_id' => $this->worker, 'v3_lease_until' => now()->addMinutes(10)])->save();
        return $bot;
    }

    private function signedPost(string $path, array $data)
    {
        $body = json_encode($data); $time = (string) time(); $nonce = bin2hex(random_bytes(16)); $tool = 'nro-trading-v3';
        return $this->postJson($path, $data, ['X-Tool-Id' => $tool, 'X-Tool-Timestamp' => $time, 'X-Tool-Nonce' => $nonce,
            'X-Tool-Signature' => hash_hmac('sha256', implode("\n", ['POST', $path, $tool, $time, $nonce, hash('sha256', $body)]), $this->secret)]);
    }

    private function batch(array $activities)
    {
        return $this->signedPost('/app/v3/activities', ['worker_id' => $this->worker, 'activities' => $activities]);
    }

    public function test_batch_reads_all_bots_in_two_selects_queues_once_and_never_broadcasts_in_http(): void
    {
        $frames = [];
        foreach (['gem_bot' => 16, 'bot' => 24] as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $bot = $this->bot($type);
                $frames[] = ['bot_type' => $type, 'bot_id' => $bot->id, 'code' => 'ready', 'map_id' => 84, 'zone' => 41, 'online_seconds' => 60];
            }
        }
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->batch($frames)->assertOk()->assertJsonPath('accepted_count', 40)->assertJsonPath('activity_delivered', true)->assertJsonCount(0, 'rejected');
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(2, $queries);
        foreach ($queries as $query) $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        Queue::assertPushed(PublishTradingBotActivities::class, 1);
        Queue::assertPushedOn('trading-activity', PublishTradingBotActivities::class);
        Event::assertNotDispatched(TradingBotActivity::class); Event::assertNotDispatched(UserEvent::class);
        $this->runQueuedTradingActivities();
        Event::assertDispatchedTimes(TradingBotActivity::class, 40);
        $this->assertDatabaseCount('inventory_movements', 0); $this->assertDatabaseCount('transactions', 0);
    }

    public function test_batch_rejects_unowned_missing_or_wrong_module_codes_without_harming_valid_frames(): void
    {
        $own = $this->bot(); $other = $this->bot('bot');
        $other->forceFill(['v3_worker_id' => (string) Str::uuid()])->save();
        $this->batch([
            ['bot_type' => 'gem_bot', 'bot_id' => $own->id, 'code' => 'gifting'],
            ['bot_type' => 'bot', 'bot_id' => $other->id, 'code' => 'ready'],
            ['bot_type' => 'gem_bot', 'bot_id' => $own->id + 999999, 'code' => 'ready'],
            ['bot_type' => 'gem_bot', 'bot_id' => $own->id, 'code' => 'refilling'],
        ])->assertOk()->assertJsonPath('accepted_count', 1)->assertJsonCount(3, 'rejected')
            ->assertJsonPath('rejected.0.reason', 'not_owner')->assertJsonPath('rejected.1.reason', 'not_found')
            ->assertJsonPath('rejected.2.reason', 'invalid_code');
        $this->runQueuedTradingActivities();
        Event::assertDispatchedTimes(TradingBotActivity::class, 1);
        Event::assertDispatched(TradingBotActivity::class, fn($event) => $event->activity['code'] === 'gifting');
        $this->batch([['bot_type' => 'gem_bot', 'bot_id' => $own->id, 'code' => 'ready', 'customer_message' => 'private']])->assertUnprocessable();
    }

    public function test_worker_drops_superseded_and_expired_frames_instead_of_replaying_old_activity(): void
    {
        $bot = $this->bot();
        $this->batch([['bot_type' => 'gem_bot', 'bot_id' => $bot->id, 'code' => 'travelling']])->assertOk();
        $first = Queue::pushed(PublishTradingBotActivities::class)->last();
        $this->batch([['bot_type' => 'gem_bot', 'bot_id' => $bot->id, 'code' => 'ready']])->assertOk();
        $latest = Queue::pushed(PublishTradingBotActivities::class)->last();
        $first->handle(); Event::assertNotDispatched(TradingBotActivity::class);
        $latest->handle();
        Event::assertDispatchedTimes(TradingBotActivity::class, 1);
        Event::assertDispatched(TradingBotActivity::class, fn($event) => $event->activity['code'] === 'ready');
        Event::fake(); $this->travel(36)->seconds();
        $latest->handle(); Event::assertNotDispatched(TradingBotActivity::class);
    }

    public function test_sync_queue_or_queue_failure_returns_best_effort_failure_without_inline_broadcast(): void
    {
        $bot = $this->bot(); $frame = ['bot_type' => 'gem_bot', 'bot_id' => $bot->id, 'code' => 'ready'];
        config(['trading_v3.activity_queue_connection' => 'sync']);
        $this->batch([$frame])->assertOk()->assertJsonPath('activity_delivered', false);
        Queue::assertNotPushed(PublishTradingBotActivities::class); Event::assertNotDispatched(TradingBotActivity::class);
        config(['trading_v3.activity_queue_connection' => 'redis']);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $this->batch([$frame])->assertOk()->assertJsonPath('activity_delivered', false);
        Event::assertNotDispatched(TradingBotActivity::class);
    }

    public function test_customer_notice_stays_private_and_waits_for_queue_worker(): void
    {
        $bot = $this->bot(); $order = GemTransaction::create(['server_id' => $this->server->id, 'user_id' => User::factory()->create()->id,
            'character_name' => 'private-customer', 'gem_qty' => 30, 'delivery_method' => 'consignment', 'status' => 'pending',
            'amount_vnd' => 10000, 'price_at_transaction' => 10]);
        $this->signedPost('/app/v3/gem/bots/'.$bot->id.'/activity', ['worker_id' => $this->worker, 'code' => 'name_mismatch',
            'order_id' => $order->id, 'customer_message' => 'private message'])->assertOk()->assertJsonPath('activity_delivered', true);
        Event::assertNotDispatched(TradingBotActivity::class); Event::assertNotDispatched(UserEvent::class);
        $this->runQueuedTradingActivities();
        Event::assertDispatched(TradingBotActivity::class, fn($event) => !isset($event->activity['customer_message'], $event->activity['order_id']));
        Event::assertDispatched(UserEvent::class, fn($event) => $event->userId === $order->user_id && $event->message === 'private message');
    }
}
