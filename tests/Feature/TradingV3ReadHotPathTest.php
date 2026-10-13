<?php

namespace Tests\Feature;

use App\Http\Resources\AppAuto\VersionThree\GoldBotResource;
use App\Models\{Bot, GemBot, GoldTransaction, InventoryMovement, Server, User};
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingV3ReadHotPathTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private int $loginId;
    private string $secret = 'test-only-read-hot-path-secret-at-least-32';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config(['trading_v3.enabled' => true, 'trading_v3.hmac_secret' => $this->secret]);
        $this->server = Server::create(['name' => 'hot-path-test', 'status' => true]);
        $this->loginId = (int) DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert(['id' => $this->loginId, 'name' => 'test-login', 'ip' => 'test.example', 'port' => '14445']);
    }

    private function bot(int $bars = 10, int $pure = 100, ?Server $server = null): Bot
    {
        return Bot::create(['name' => 'read-test', 'account_name' => 'test-'.Str::uuid(), 'account_password' => 'dummy-test-password',
            'type' => 'selling_main', 'server_id' => ($server ?? $this->server)->id, 'server_game_id' => $this->loginId,
            'gold_bar_qty' => $bars, 'gold_qty' => $pure, 'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41',
            'coordinates' => '652-336', 'status' => true]);
    }

    private function signedGet(string $path)
    {
        $time = (string) time(); $nonce = bin2hex(random_bytes(16)); $tool = 'nro-trading-v3';
        return $this->get($path, ['Accept' => 'application/json', 'X-Tool-Id' => $tool, 'X-Tool-Timestamp' => $time, 'X-Tool-Nonce' => $nonce,
            'X-Tool-Signature' => hash_hmac('sha256', implode("\n", ['GET', $path, $tool, $time, $nonce, hash('sha256', '')]), $this->secret)]);
    }

    private function order(Bot $bot, string $type, int $bars, int $pure, array $progress = [], string $status = 'processing'): GoldTransaction
    {
        return GoldTransaction::create(['bot_id' => $bot->id, 'server_id' => $bot->server_id, 'user_id' => User::factory()->create()->id,
            'type' => $type, 'status' => $status, 'character_name' => 'read-buyer', 'gold_bar_qty' => $bars, 'pure_gold_qty' => $pure,
            'gold_qty' => $bars * 37000000 + $pure, 'amount_vnd' => 10000, 'price_at_transaction' => 1000,
            'delivery_data' => ['progress' => $progress]]);
    }

    private function transfer(Bot $from, Bot $to, int $bars, int $pure, string $status = 'claimed'): void
    {
        InventoryMovement::create(['server_id' => $from->server_id, 'bot_id' => $from->id, 'bot_type' => 'bot', 'asset_type' => 'workflow',
            'movement_type' => 'reservation', 'quantity_delta' => 0, 'balance_before' => 0, 'balance_after' => 0,
            'transaction_type' => 'gold_transfer', 'source' => 'app', 'occurred_at' => now(),
            'meta' => ['id' => (string) Str::uuid(), 'status' => $status, 'from_bot_id' => $from->id, 'to_bot_id' => $to->id,
                'bars_qty' => $bars, 'pure_gold_qty' => $pure]]);
    }

    public function test_full_gold_list_uses_five_selects_instead_of_queries_per_bot(): void
    {
        for ($i = 0; $i < 74; $i++) {
            $bot = $this->bot();
            if ($i === 73) $bot->update(['status' => false]);
        }
        DB::enableQueryLog(); DB::flushQueryLog();
        $response = $this->signedGet('/app/v3/gold/bots');
        $selects = collect(DB::getQueryLog())->filter(fn($q) => str_starts_with(strtolower(ltrim($q['query'])), 'select'));
        DB::disableQueryLog();
        $response->assertOk()->assertJsonCount(74, 'data')->assertJsonPath('data.73.status', false)
            ->assertJsonPath('data.0.available', ['bars' => 10, 'pure' => 100])->assertJsonPath('data.0.receive_room', 989);
        $this->assertCount(5, $selects, $selects->pluck('query')->implode("\n"));
    }

    public function test_prefetched_reservations_match_fresh_reads_and_are_not_used_for_financial_checks(): void
    {
        $from = $this->bot(10, 95); $to = $this->bot(998, 0); $outside = $this->bot(500, 500);
        $order = $this->order($from, 'order', 5, 40, ['bars' => 3, 'pure' => 10]);
        $this->order($from, 'order', 4, 80);
        $this->order($from, 'order', 999, 999, [], 'pending');
        $this->order($from, 'order', 999, 999, [], 'completed');
        $this->order($to, 'import', 4, 0, ['bars' => 2, 'pure' => 0]);
        $this->transfer($from, $outside, 2, 7);
        $this->transfer($outside, $to, 5, 0);
        $this->transfer($from, $to, 999, 999, 'completed');
        $bots = Bot::with(['server', 'loginServer'])->whereIn('id', [$from->id, $to->id])->get();
        $expected = $bots->mapWithKeys(fn($bot) => [$bot->id => ['available' => GoldTradingService::available($bot),
            'receive_room' => GoldTradingService::receiveRoom($bot)]]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $prefetched = GoldTradingService::prefetchReadStock($bots);
        $data = GoldBotResource::collection($prefetched)->response()->getData(true)['data'];
        $selects = collect(DB::getQueryLog())->filter(fn($q) => str_starts_with(strtolower(ltrim($q['query'])), 'select'));
        DB::disableQueryLog();
        $this->assertCount(2, $selects);
        foreach ($data as $row) {
            $this->assertSame($expected[$row['id']]['available'], $row['available']);
            $this->assertSame($expected[$row['id']]['receive_room'], $row['receive_room']);
        }
        $this->assertSame(['bars' => 2, 'pure' => 0], $data[0]['available']);
        $this->assertSame(0, $data[1]['receive_room']);
        $order->update(['delivery_data' => ['progress' => ['bars' => 5, 'pure' => 40]]]);
        $this->assertSame(['bars' => 4, 'pure' => 8], GoldTradingService::available($prefetched->first()));
    }

    public function test_filtered_lists_do_not_reuse_another_request_or_server_stock(): void
    {
        $first = $this->bot(4, 40); $otherServer = Server::create(['name' => 'other', 'status' => true]);
        $second = $this->bot(9, 90, $otherServer); $this->order($second, 'order', 2, 10);
        $this->signedGet('/app/v3/gold/bots?server_id='.$this->server->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.0.available', ['bars' => 4, 'pure' => 40]);
        $this->signedGet('/app/v3/gold/bots?server_id='.$otherServer->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.0.available', ['bars' => 7, 'pure' => 80]);
    }

    public function test_public_ticket_capacity_cache_expires_with_the_nearest_lease(): void
    {
        $this->travelTo(now()->startOfSecond());
        $create = fn(string $name, int $seconds) => GemBot::create(['name' => $name, 'account_name' => $name, 'account_password' => 'dummy',
            'server_id' => $this->server->id, 'status' => true, 'gem_qty' => 100, 'gift_ticket_qty' => 10, 'consign_item_qty' => 1,
            'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41', 'coordinates' => '652-336',
            'v3_worker_id' => (string) Str::uuid(), 'v3_lease_until' => now()->addSeconds($seconds)]);
        $create('early', 90); $create('later', 600);
        $url = '/api/gem/bots?server_id='.$this->server->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.gift_available_gems', 100)->assertJsonPath('data.1.gift_available_gems', 100);
        $this->travel(91)->seconds();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.gift_available_gems', 0)->assertJsonPath('data.1.gift_available_gems', 100);
        $this->travelBack();
    }
}
