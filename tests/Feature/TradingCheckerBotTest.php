<?php

namespace Tests\Feature;

use App\Events\TradingBotActivity;
use App\Http\Controllers\Admin\BotController;
use App\Models\{Bot, GemBot, GoldTransaction, Server, User};
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Event, Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TradingCheckerBotTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private int $loginId;
    private string $secret = 'checker-offline-test-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::fake();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config(['trading_v3.enabled' => true, 'trading_v3.hmac_secret' => $this->secret]);
        config(['services.app_api_key' => 'checker-legacy-test-key']);
        $this->server = Server::create(['name' => 'Checker test', 'status' => true]);
        $this->loginId = (int) DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert(['id' => $this->loginId, 'name' => 'Offline', 'ip' => 'test.invalid', 'port' => '14445']);
    }

    private function data(string $type = 'checker'): array
    {
        return ['name' => 'Checker', 'account_name' => 'offline-test-'.Str::uuid(), 'account_password' => 'test-password',
            'server_id' => $this->server->id, 'server_game_id' => $this->loginId, 'type' => $type, 'status' => true,
            'map_id' => '84', 'area_number' => '1', 'coordinates' => '652-336', 'gold_qty' => 0, 'gold_bar_qty' => 10];
    }

    private function signed(string $method, string $path, array $data = [])
    {
        $body = $method === 'GET' ? '' : json_encode($data);
        $time = (string) time(); $nonce = bin2hex(random_bytes(16)); $tool = 'nro-trading-v3';
        $headers = ['X-Tool-Id' => $tool, 'X-Tool-Timestamp' => $time, 'X-Tool-Nonce' => $nonce,
            'X-Tool-Signature' => hash_hmac('sha256', implode("\n", [$method, $path, $tool, $time, $nonce, hash('sha256', $body)]), $this->secret)];
        return $method === 'GET' ? $this->get($path, [...$headers, 'Accept' => 'application/json']) : $this->json($method, $path, $data, $headers);
    }

    public function test_full_tool_list_includes_enabled_and_disabled_checkers_but_public_lists_exclude_them(): void
    {
        $checker = Bot::create($this->data());
        $off = Bot::create([...$this->data(), 'status' => false]);
        $seller = Bot::create($this->data('selling_main, import_main'));
        // Simulate a malformed direct DB import; it still must not become a seller.
        Bot::create($this->data('selling_main,checker,import_main'));
        $rows = $this->signed('GET', '/app/v3/gold/bots')->assertOk()->assertJsonCount(4, 'data')->json('data');
        $this->assertSame(['checker'], $rows[0]['types']);
        $this->assertSame($checker->id, $rows[0]['id']);
        $this->assertSame($this->loginId, $rows[0]['server_login_id']);
        $this->assertSame('test.invalid', $rows[0]['login']['ip']);
        $this->assertSame($off->id, $rows[1]['id']);
        $this->assertFalse($rows[1]['status']);
        foreach (['selling_main', 'import_main'] as $role) {
            $this->getJson('/api/bots?server_id='.$this->server->id.'&type='.$role)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $seller->id);
            $this->getJson('/api/bots/overview')->assertOk()->assertJsonCount(1, 'data.'.$role)
                ->assertJsonPath('data.'.$role.'.0.id', $seller->id);
        }
    }

    public function test_admin_can_create_checker_and_preserve_csv_roles_but_cannot_combine_checker(): void
    {
        $this->actingAs(User::factory()->create());
        $controller = app(BotController::class);
        foreach (['checker', 'selling_main,import_sub,auto_sell_bar'] as $type) {
            $data = $this->data($type);
            $controller->store(Request::create('/admin/bots', 'POST', $data));
            $this->assertDatabaseHas('bots', ['account_name' => $data['account_name'], 'type' => $type]);
        }
        $bot = Bot::where('type', 'checker')->firstOrFail();
        foreach (['checker,selling_main', 'import_sub, checker', 'checker,auto_sell_bar'] as $type) {
            foreach (['store', 'update'] as $action) {
                $data = $this->data($type);
                try {
                    $request = Request::create('/admin/bots', $action === 'store' ? 'POST' : 'PUT', $data);
                    $action === 'store' ? $controller->store($request) : $controller->update($request, $bot);
                    $this->fail('A checker was combined with a financial role.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('type', $exception->errors());
                }
            }
        }
        $this->assertDatabaseCount('bots', 2);
        $this->assertSame('checker', $bot->fresh()->type);
    }

    public function test_legacy_lists_exclude_checkers_and_reject_new_checker_order_or_stock_writes(): void
    {
        $checker = Bot::create($this->data());
        Bot::create($this->data('checker,selling_main'));
        $seller = Bot::create($this->data('selling_main'));
        $headers = ['X-APP-KEY' => 'checker-legacy-test-key'];
        foreach (['v1', 'v2'] as $version) {
            $this->getJson('/app/'.$version.'/bots', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $seller->id);
            $this->putJson('/app/'.$version.'/bots/'.$checker->id, ['gold_qty' => 0, 'gold_bar_qty' => 9], $headers)->assertUnprocessable();
            $order = GoldTransaction::create(['type' => 'order', 'bot_id' => $checker->id, 'user_id' => User::factory()->create()->id,
                'server_id' => $this->server->id, 'character_name' => 'customer', 'gold_qty' => 37000000,
                'gold_bar_qty' => 1, 'pure_gold_qty' => 0, 'amount_vnd' => 10000, 'price_at_transaction' => 1000, 'status' => 'pending']);
            $data = ['status' => 'processing', 'bot_id' => $checker->id, 'gold_qty' => 0, 'gold_bar_qty' => 9];
            $this->putJson('/app/'.$version.'/gold-transactions/'.$order->id, $data, $headers)->assertUnprocessable();
            $this->assertSame('pending', $order->fresh()->status);
            // A terminal legacy ACK performs no new work, even if the account is now a checker.
            $order->update(['status' => 'completed']);
            $this->putJson('/app/'.$version.'/gold-transactions/'.$order->id, [...$data, 'status' => 'completed'], $headers)->assertOk();
        }
        $this->assertSame(10, $checker->fresh()->gold_bar_qty);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_legacy_stock_receipt_ack_after_checker_role_change_never_replays_old_inventory(): void
    {
        $bot = Bot::create($this->data('selling_main'));
        $headers = ['X-APP-KEY' => 'checker-legacy-test-key'];
        $order = GoldTransaction::create(['type' => 'order', 'bot_id' => $bot->id, 'user_id' => User::factory()->create()->id,
            'server_id' => $this->server->id, 'character_name' => 'customer', 'gold_qty' => 37000000,
            'gold_bar_qty' => 1, 'pure_gold_qty' => 0, 'amount_vnd' => 10000, 'price_at_transaction' => 1000, 'status' => 'completed']);
        $body = ['transactions_id' => $order->id, 'gold_qty' => 0, 'gold_bar_qty' => 9];
        $path = '/app/v2/bots/'.$bot->id;
        $this->putJson($path, $body, $headers)->assertOk();
        $this->assertDatabaseCount('inventory_movements', 1);
        $bot->update(['type' => 'checker', 'gold_bar_qty' => 8]);
        $this->putJson($path, $body, $headers)->assertOk();
        $this->assertSame(8, $bot->fresh()->gold_bar_qty);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->putJson($path, [...$body, 'gold_bar_qty' => 7], $headers)->assertUnprocessable();
        $this->assertSame(8, $bot->fresh()->gold_bar_qty);
    }

    public function test_checker_cannot_start_customer_orders_or_inventory_transfers_even_with_mixed_imported_roles(): void
    {
        $seller = Bot::create($this->data('selling_main'));
        $importer = Bot::create($this->data('import_main'));
        $aux = Bot::create($this->data('selling_sub'));
        foreach (['checker', 'checker,selling_main,import_main,selling_sub'] as $type) {
            $checker = Bot::create($this->data($type));
            $this->assertFalse(GoldTradingService::auxiliary($checker));
            foreach (['order', 'import'] as $direction) {
                $order = GoldTransaction::create(['type' => $direction, 'user_id' => User::factory()->create()->id,
                    'server_id' => $this->server->id, 'character_name' => 'customer', 'gold_qty' => 37000000,
                    'gold_bar_qty' => 1, 'pure_gold_qty' => 0, 'amount_vnd' => 10000, 'price_at_transaction' => 1000, 'status' => 'pending']);
                $this->signed('PUT', '/app/v3/gold-transactions/'.$order->id,
                    ['bot_id' => $checker->id, 'action' => 'start'])->assertUnprocessable();
                $this->assertSame('pending', $order->fresh()->status);
            }
            foreach ([[$checker, $seller], [$importer, $checker], [$aux, $checker], [$checker, $aux]] as [$from, $to]) {
                $this->signed('PUT', '/app/v3/gold-transfers/'.Str::uuid(), ['action' => 'claim',
                    'from_bot_id' => $from->id, 'to_bot_id' => $to->id, 'bars_qty' => 1, 'pure_gold_qty' => 0])->assertUnprocessable();
            }
        }
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_checker_and_recovery_activity_codes_are_transient_for_both_gem_and_gold(): void
    {
        $checker = Bot::create($this->data());
        $gem = GemBot::create(collect($this->data())->except(['type', 'gold_qty', 'gold_bar_qty'])->all());
        $codes = ['checking_presence', 'checker_idle', 'presence_wait', 'map_recovery', 'presence_ready',
            'packet_stalled', 'presence_unverified', 'checker_recovery'];
        foreach (['gold' => $checker, 'gem' => $gem] as $module => $bot) {
            $before = $bot->refresh()->getAttributes();
            foreach ($codes as $code) {
                Event::fake();
                $this->signed('POST', "/app/v3/{$module}/bots/{$bot->id}/activity", ['code' => $code])->assertOk();
                Event::assertDispatched(TradingBotActivity::class, fn ($event) => $event->activity['code'] === $code);
            }
            $this->assertSame($before, $bot->fresh()->getAttributes());
        }
        Event::fake();
        $this->signed('POST', '/app/v3/activities', ['activities' => [
            ['bot_type' => 'bot', 'bot_id' => $checker->id, 'code' => 'checking_presence'],
            ['bot_type' => 'gem_bot', 'bot_id' => $gem->id, 'code' => 'packet_stalled'],
        ]])->assertOk()->assertJsonPath('accepted_count', 2)->assertJsonPath('rejected', []);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
