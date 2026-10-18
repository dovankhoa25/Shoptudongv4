<?php

namespace Tests\Feature;

use App\Events\{TradingBotActivity, UserEvent};
use App\Models\{Bot, GemBot, GemTransaction, Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingV3ActivityQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $worker = '00000000-0000-4000-8000-000000000401';
    private string $secret = 'test-only-activity-secret-at-least-thirty-two';
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trading_v3.enabled' => true, 'trading_v3.hmac_secret' => $this->secret]);
        Queue::fake(); Event::fake(); $this->freezeTime();
        $this->server = Server::create(['name' => 'activity-test', 'status' => true]);
    }

    private function bot(string $type = 'gem_bot'): Bot|GemBot
    {
        $data = ['name' => 'activity-test', 'account_name' => 'dummy-'.Str::uuid(), 'account_password' => 'dummy-password',
            'server_id' => $this->server->id, 'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41', 'coordinates' => '652-336', 'status' => true];
        return $type === 'gem_bot' ? GemBot::create([...$data, 'gem_qty' => 100, 'gift_ticket_qty' => 10])
            : Bot::create([...$data, 'type' => 'selling_main', 'gold_qty' => 0, 'gold_bar_qty' => 10]);
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

    public function test_batch_reads_bots_in_two_selects_and_delivers_after_response_without_any_queue_job(): void
    {
        $frames = [];
        foreach (['gem_bot' => 16, 'bot' => 24] as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $bot = $this->bot($type); $frames[] = ['bot_type' => $type, 'bot_id' => $bot->id, 'code' => 'ready', 'map_id' => 84, 'zone' => 41, 'online_seconds' => 60];
            }
        }
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->batch($frames)->assertOk()->assertJsonPath('accepted_count', 40)->assertJsonPath('activity_delivered', true)->assertJsonCount(0, 'rejected');
        $queries = DB::getQueryLog(); DB::disableQueryLog(); $this->assertCount(2, $queries);
        foreach ($queries as $query) $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        Queue::assertNothingPushed(); Event::assertDispatchedTimes(TradingBotActivity::class, 40); Event::assertNotDispatched(UserEvent::class);
        $this->assertDatabaseCount('inventory_movements', 0); $this->assertDatabaseCount('transactions', 0);
    }

    public function test_batch_accepts_bot_presence_and_still_rejects_missing_bot_or_wrong_module_codes(): void
    {
        $own = $this->bot(); $other = $this->bot('bot');
        $this->batch([
            ['bot_type' => 'gem_bot', 'bot_id' => $own->id, 'code' => 'gifting'],
            ['bot_type' => 'bot', 'bot_id' => $other->id, 'code' => 'ready'],
            ['bot_type' => 'gem_bot', 'bot_id' => $own->id + 999999, 'code' => 'ready'],
            ['bot_type' => 'gem_bot', 'bot_id' => $own->id, 'code' => 'refilling'],
        ])->assertOk()->assertJsonPath('accepted_count', 2)->assertJsonCount(2, 'rejected')
            ->assertJsonPath('rejected.0.reason', 'not_found')->assertJsonPath('rejected.1.reason', 'invalid_code');
        Event::assertDispatchedTimes(TradingBotActivity::class, 2);
        Event::assertDispatched(TradingBotActivity::class, fn($event) => $event->activity['code'] === 'gifting');
        $this->batch([['bot_type' => 'gem_bot', 'bot_id' => $own->id, 'code' => 'ready', 'customer_message' => 'private']])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_repeated_requests_do_not_accumulate_or_replay_previous_response_callbacks(): void
    {
        $bot = $this->bot();
        $this->batch([['bot_type' => 'gem_bot', 'bot_id' => $bot->id, 'code' => 'travelling']])->assertOk();
        Event::assertDispatchedTimes(TradingBotActivity::class, 1); Event::fake();
        $this->batch([['bot_type' => 'gem_bot', 'bot_id' => $bot->id, 'code' => 'ready']])->assertOk();
        Event::assertDispatchedTimes(TradingBotActivity::class, 1);
        Event::assertDispatched(TradingBotActivity::class, fn($event) => $event->activity['code'] === 'ready'); Queue::assertNothingPushed();
    }

    public function test_customer_notice_stays_private_and_has_no_financial_effect(): void
    {
        $bot = $this->bot(); $order = GemTransaction::create(['server_id' => $this->server->id, 'user_id' => User::factory()->create()->id,
            'character_name' => 'private-customer', 'gem_qty' => 30, 'delivery_method' => 'consignment', 'status' => 'pending',
            'amount_vnd' => 10000, 'price_at_transaction' => 10]);
        $botBefore = $bot->refresh()->getAttributes(); $orderBefore = $order->refresh()->getAttributes();
        $this->signedPost('/app/v3/gem/bots/'.$bot->id.'/activity', ['worker_id' => $this->worker, 'code' => 'name_mismatch',
            'order_id' => $order->id, 'customer_message' => 'private message'])->assertOk()->assertJsonPath('activity_delivered', true);
        Event::assertDispatched(TradingBotActivity::class, fn($event) => !isset($event->activity['customer_message'], $event->activity['order_id']));
        Event::assertDispatched(UserEvent::class, fn($event) => $event->userId === $order->user_id && $event->message === 'private message');
        Queue::assertNothingPushed(); $this->assertSame($botBefore, $bot->refresh()->getAttributes()); $this->assertSame($orderBefore, $order->refresh()->getAttributes());
        $this->assertDatabaseCount('inventory_movements', 0); $this->assertDatabaseCount('transactions', 0);
    }
}
