<?php

namespace Tests\Feature;

use App\Models\{Bot, GemBot, GemTransaction, GoldTransaction, InventoryMovement, Server, User};
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Event};
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingV3BatchHeartbeatTest extends TestCase
{
    use RefreshDatabase;
    private string $worker = '00000000-0000-4000-8000-000000000111';
    private string $other = '00000000-0000-4000-8000-000000000112';
    private string $secret = 'batch-heartbeat-test-secret-at-least-32';
    private Server $server;
    private int $loginId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled', true);
        config()->set('trading_v3.hmac_secret', $this->secret);
        Event::fake();
        $this->server = Server::create(['name' => 'batch-test', 'status' => true]);
        $this->loginId = (int) DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert(['id' => $this->loginId, 'name' => 'login-test', 'ip' => 'test.example', 'port' => '14445']);
    }

    private function signed(string $path, array $data, string $method = 'POST')
    {
        $body = $method === 'GET' ? '' : json_encode($data);
        $time = (string) time(); $nonce = bin2hex(random_bytes(16)); $tool = 'nro-trading-v3';
        $headers = ['X-Tool-Id' => $tool, 'X-Tool-Timestamp' => $time, 'X-Tool-Nonce' => $nonce,
            'X-Tool-Signature' => hash_hmac('sha256', implode("\n", [$method, $path, $tool, $time, $nonce, hash('sha256', $body)]), $this->secret)];
        return $method === 'GET' ? $this->get($path, [...$headers, 'Accept' => 'application/json']) : $this->json($method, $path, $data, $headers);
    }

    private function bot(bool $gem = true, bool $enabled = true): GemBot|Bot
    {
        $attributes = ['name' => 'batch-bot', 'account_name' => 'fake-'.Str::uuid(), 'account_password' => 'fake-test-password',
            'server_id' => $this->server->id, 'server_game_id' => $this->loginId, 'map_id' => '84',
            'area_number' => '41', 'coordinates' => '652-336', 'status' => $enabled];
        return $gem ? GemBot::create([...$attributes, 'gem_qty' => 100, 'gift_ticket_qty' => 10, 'consign_item_qty' => 3])
            : Bot::create([...$attributes, 'type' => 'selling_main', 'gold_bar_qty' => 10, 'gold_qty' => 0]);
    }

    private function heartbeat(array $gems = [], array $gold = [], int $interval = 150, ?string $worker = null)
    {
        return $this->signed('/app/v3/heartbeat', ['worker_id' => $worker ?? $this->worker,
            'renewal_interval_seconds' => $interval, 'gem_bot_ids' => $gems, 'gold_bot_ids' => $gold]);
    }

    private function order(GemBot|Bot $bot, ?int $version, ?string $owner): void
    {
        $attributes = ['bot_id' => $bot->id, 'server_id' => $bot->server_id, 'user_id' => User::factory()->create()->id,
            'character_name' => 'test-client', 'status' => 'processing', 'processing_version' => $version,
            'processing_owner' => $owner, 'amount_vnd' => 10000, 'price_at_transaction' => 1000];
        if ($bot instanceof GemBot) GemTransaction::create([...$attributes, 'gem_qty' => 20]);
        else GoldTransaction::create([...$attributes, 'type' => 'order', 'gold_qty' => 37000000, 'gold_bar_qty' => 1, 'pure_gold_qty' => 0]);
    }

    public function test_policy_advertises_capability_and_batch_grants_long_leases_without_stock_or_target_changes(): void
    {
        $this->signed('/app/v3/config', [], 'GET')->assertOk()->assertJsonPath('data.batch_heartbeat_supported', true)
            ->assertJsonPath('data.renewal_interval_seconds', 150);
        $gem = $this->bot(); $gold = $this->bot(false);
        $response = $this->heartbeat([$gem->id], [$gold->id], 600)->assertOk()->assertJsonPath('lease_seconds', 660)
            ->assertJsonPath('gem_bots.0.granted', true)->assertJsonPath('gold_bots.0.code', 'renewed');
        $gem->refresh(); $gold->refresh();
        $this->assertEqualsWithDelta(660, now()->diffInSeconds($gem->v3_lease_until), 2);
        $this->assertSame($this->worker, $gem->v3_worker_id);
        $this->assertSame($this->worker, $gold->v3_worker_id);
        $this->assertSame(100, $gem->gem_qty); $this->assertSame(10, $gold->gold_bar_qty);
        $this->assertSame('84', $gem->map_id); $this->assertSame('652-336', $gold->coordinates);
        $this->assertTrue($gem->status); $this->assertTrue($gold->status);
        $this->assertStringNotContainsString('fake-test-password', $response->getContent());
        $this->assertStringNotContainsString('account_password', $response->getContent());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_partial_rejections_do_not_extend_disabled_other_owner_or_missing_bot(): void
    {
        $allowed = $this->bot(); $off = $this->bot(true, false); $taken = $this->bot(); $serverOff = $this->bot();
        $disabledServer = Server::create(['name' => 'disabled', 'status' => false]);
        $serverOff->update(['server_id' => $disabledServer->id]);
        $taken->forceFill(['v3_worker_id' => $this->other, 'v3_lease_until' => now()->addMinutes(10)])->save();
        $until = $taken->v3_lease_until->toIso8601String();
        $this->heartbeat([$serverOff->id, $taken->id, $off->id, $allowed->id, 999999])->assertOk()
            ->assertJsonPath('gem_bots.0.code', 'renewed')->assertJsonPath('gem_bots.1.code', 'disabled')
            ->assertJsonPath('gem_bots.2.code', 'owned_elsewhere')->assertJsonPath('gem_bots.3.code', 'server_disabled')
            ->assertJsonPath('gem_bots.4.code', 'not_found');
        $this->assertNull($off->refresh()->v3_worker_id); $this->assertNull($serverOff->refresh()->v3_worker_id);
        $this->assertSame($this->other, $taken->refresh()->v3_worker_id);
        $this->assertSame($until, $taken->v3_lease_until->toIso8601String());
    }

    public function test_expired_lease_does_not_allow_takeover_of_unfinished_orders_or_gold_transfers(): void
    {
        $legacy = $this->bot(); $gem = $this->bot(); $gold = $this->bot(false); $transferred = $this->bot(false);
        $this->order($legacy, null, null); $this->order($gem, 3, $this->other); $this->order($gold, 3, $this->other);
        InventoryMovement::create(['server_id' => $transferred->server_id, 'bot_id' => $transferred->id, 'bot_type' => 'bot',
            'asset_type' => 'gold_bar', 'movement_type' => 'reservation', 'quantity_delta' => 0, 'balance_before' => 10, 'balance_after' => 10,
            'transaction_type' => 'gold_transfer', 'idempotency_key' => 'batch-transfer-test', 'source' => 'app', 'occurred_at' => now(),
            'meta' => ['status' => 'claimed', 'from_bot_id' => $transferred->id, 'to_bot_id' => $gold->id, 'processing_owner' => $this->other]]);
        $this->heartbeat([$legacy->id, $gem->id], [$gold->id, $transferred->id])->assertOk()
            ->assertJsonPath('gem_bots.0.code', 'legacy_order')->assertJsonPath('gem_bots.1.code', 'unfinished_order')
            ->assertJsonPath('gold_bots.0.code', 'unfinished_order')->assertJsonPath('gold_bots.1.code', 'unfinished_transfer');
        $this->assertNull($gem->refresh()->v3_worker_id); $this->assertNull($transferred->refresh()->v3_worker_id);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_same_owner_can_resume_expired_lease_and_other_worker_cannot_take_it_after_batch_commit(): void
    {
        $gem = $this->bot(); $gold = $this->bot(false);
        $this->order($gem, 3, $this->worker); $this->order($gold, 3, $this->worker);
        foreach ([$gem, $gold] as $bot) $bot->forceFill(['v3_worker_id' => $this->worker, 'v3_lease_until' => now()->subSeconds(10)])->save();
        $this->heartbeat([$gem->id], [$gold->id])->assertOk()->assertJsonPath('gem_bots.0.granted', true)->assertJsonPath('gold_bots.0.granted', true);
        $this->heartbeat([$gem->id], [$gold->id], 150, $this->other)->assertOk()
            ->assertJsonPath('gem_bots.0.code', 'owned_elsewhere')->assertJsonPath('gold_bots.0.code', 'owned_elsewhere');
        $this->assertSame($this->worker, $gem->refresh()->v3_worker_id);
    }

    public function test_batch_uses_constant_query_count_and_one_bulk_write_per_bot_table(): void
    {
        $gems = $gold = [];
        for ($i = 0; $i < 20; $i++) { $gems[] = $this->bot()->id; $gold[] = $this->bot(false)->id; }
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->heartbeat(array_reverse($gems), array_reverse($gold))->assertOk()->assertJsonCount(20, 'gem_bots')->assertJsonCount(20, 'gold_bots');
        $queries = collect(DB::getQueryLog())->pluck('query'); DB::disableQueryLog();
        $selects = $queries->filter(fn ($sql) => str_starts_with(strtolower($sql), 'select'));
        $updates = $queries->filter(fn ($sql) => str_starts_with(strtolower($sql), 'update'));
        $this->assertLessThanOrEqual(8, $selects->count(), implode("\n", $queries->all()));
        $this->assertCount(2, $updates);
        $this->assertTrue($updates->contains(fn ($sql) => str_contains($sql, '"gem_bots"')));
        $this->assertTrue($updates->contains(fn ($sql) => str_contains($sql, '"bots"')));
        $botSelects = $selects->filter(fn ($sql) => str_contains($sql, 'from "bots"') || str_contains($sql, 'from "gem_bots"'));
        foreach ($botSelects as $sql) $this->assertStringContainsString('order by "id" asc', $sql);
    }

    public function test_active_owner_renewal_keeps_public_cache_generations_but_acquisition_invalidates_once(): void
    {
        $gem = $this->bot(); $gold = $this->bot(false);
        $groups = ['public:gembot', 'public:server-prices', 'public:bots'];
        foreach ($groups as $group) Cache::forever(ApiCache::key('generation', $group), 'before');
        $this->heartbeat([$gem->id], [$gold->id])->assertOk();
        $generations = [];
        foreach ($groups as $group) { $generations[$group] = Cache::get(ApiCache::key('generation', $group)); $this->assertNotSame('before', $generations[$group]); }
        $this->heartbeat([$gem->id], [$gold->id])->assertOk();
        foreach ($groups as $group) $this->assertSame($generations[$group], Cache::get(ApiCache::key('generation', $group)));
        foreach ([['gem', $gem], ['gold', $gold]] as [$type, $bot]) {
            $this->signed("/app/v3/{$type}/bots/{$bot->id}", ['worker_id' => $this->worker, 'action' => 'lease'], 'PUT')->assertOk();
        }
        // Falling back to the old 90s endpoint shortens a batch lease and must retire its cached positive capacity.
        foreach ($groups as $group) {
            $this->assertNotSame($generations[$group], Cache::get(ApiCache::key('generation', $group)));
            $generations[$group] = Cache::get(ApiCache::key('generation', $group));
        }
        foreach ([['gem', $gem], ['gold', $gold]] as [$type, $bot]) {
            $this->signed("/app/v3/{$type}/bots/{$bot->id}", ['worker_id' => $this->worker, 'action' => 'lease'], 'PUT')->assertOk();
        }
        foreach ($groups as $group) $this->assertSame($generations[$group], Cache::get(ApiCache::key('generation', $group)));
    }

    public function test_shortened_batch_and_legacy_leases_retire_long_cached_public_ticket_capacity(): void
    {
        foreach (['batch', 'legacy'] as $method) {
            $this->travelTo(now()->startOfSecond());
            $gem = $this->bot();
            $this->heartbeat([$gem->id], [], 600)->assertOk()->assertJsonPath('lease_seconds', 660);
            $url = '/api/gem/bots?server_id='.$this->server->id;
            // This first read caches a positive card for 120s under the long lease.
            $this->getJson($url)->assertOk()->assertJsonPath('data.0.gift_available_gems', 100);
            $generation = Cache::get(ApiCache::key('generation', 'public:gembot'));
            if ($method === 'batch') $this->heartbeat([$gem->id], [], 10)->assertOk()->assertJsonPath('lease_seconds', 90);
            else $this->signed('/app/v3/gem/bots/'.$gem->id, ['worker_id' => $this->worker, 'action' => 'lease'], 'PUT')->assertOk();
            $this->assertNotSame($generation, Cache::get(ApiCache::key('generation', 'public:gembot')));
            // Rebuild now using the short lease, then expire it before the old 120s card would expire.
            $this->getJson($url)->assertOk()->assertJsonPath('data.0.gift_available_gems', 100);
            $this->travel(91)->seconds();
            $this->getJson($url)->assertOk()->assertJsonPath('data.0.gift_available_gems', 0);
            $this->travelBack();
            $gem->delete();
        }
    }

    public function test_validation_auth_and_invalid_gold_login_are_safe(): void
    {
        $this->postJson('/app/v3/heartbeat', ['worker_id' => $this->worker])->assertUnauthorized();
        $gem = $this->bot(); $gold = $this->bot(false);
        DB::table('server_game_login')->where('id', $this->loginId)->update(['port' => 0]);
        $this->heartbeat([$gem->id], [$gold->id])->assertOk()->assertJsonPath('gold_bots.0.code', 'login_invalid');
        $this->heartbeat([$gem->id], [], 3600)->assertOk()->assertJsonPath('lease_seconds', 3660);
        $this->heartbeat([$gem->id], [], 3601)->assertUnprocessable();
        $this->heartbeat([$gem->id, $gem->id])->assertUnprocessable();
        $this->signed('/app/v3/heartbeat', ['worker_id' => $this->worker, 'gem_bot_ids' => [$gem->id], 'status' => false, 'map_id' => 1])->assertUnprocessable();
        $this->assertSame('84', $gem->refresh()->map_id); $this->assertTrue($gem->status);
    }
}
