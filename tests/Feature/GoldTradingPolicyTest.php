<?php

namespace Tests\Feature;

use App\Models\{Bot, GoldPrice, GoldTradingPolicy, GoldTransaction, Server, User};
use App\Services\GoldBarAsset;
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\{Permission, Role};
use Tests\TestCase;

class GoldTradingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->server = Server::create(['name' => 'policy-server', 'status' => true]);
        GoldPrice::create(['server_id' => $this->server->id, 'price' => 3700, 'import_price' => 1000, 'status' => true]);
    }

    private function customer(): User
    {
        $user = User::factory()->create(['balance' => 1_000_000]);
        Passport::actingAs($user, ['profile:read', 'profile:write']);
        return $user;
    }

    private function bot(array $attributes = []): Bot
    {
        return Bot::create([
            'name' => 'policy-bot', 'account_name' => 'test-'.Str::uuid(), 'account_password' => 'test-only',
            'server_id' => $this->server->id, 'type' => 'import_sub', 'status' => true,
            'gold_qty' => 0, 'gold_bar_qty' => 0, 'nrj_gold_bar_qty' => 0,
            'map_id' => '84', 'area_number' => '1', ...$attributes,
        ]);
    }

    private function policy(string $asset, array $attributes = []): GoldTradingPolicy
    {
        return GoldTradingPolicy::create(['server_id' => $this->server->id, 'asset_type' => $asset,
            'sell_enabled' => true, 'import_enabled' => true, ...$attributes]);
    }

    private function import(int $bars, int $template = 457, int $pure = 0)
    {
        return $this->postJson('/api/imports', ['server_id' => $this->server->id, 'character_name' => 'customer',
            'gold_bar_qty' => $bars, 'gold_bar_template_id' => $template, 'pure_gold_qty' => $pure]);
    }

    public static function barAssets(): array
    {
        return [[457, 'standard'], [2032, 'nrj']];
    }

    #[DataProvider('barAssets')]
    public function test_import_limit_counts_all_bot_roles_and_disabled_bots_but_only_the_selected_asset_and_server(int $template, string $asset): void
    {
        $field = GoldBarAsset::field($template);
        $this->policy($asset, ['import_stock_limit_bars' => 500]);
        $this->bot([$field => 100, 'type' => 'import_main']);
        $this->bot([$field => 50, 'type' => 'selling_main']);
        $this->bot([$field => 150, 'type' => 'selling_sub', 'status' => false]);
        $other = Server::create(['name' => 'other', 'status' => true]);
        $this->bot(['server_id' => $other->id, $field => 999]);
        $this->bot([GoldBarAsset::field($template === 457 ? 2032 : 457) => 999]);

        $user = $this->customer();
        $this->import(201, $template)->assertUnprocessable()->assertJsonValidationErrors('gold_bar_qty');
        $this->assertDatabaseCount('gold_transactions', 0);
        $this->import(200, $template)->assertCreated();
        $this->assertSame(1_000_000, (int) $user->refresh()->balance);

        // Accepted pending imports intentionally do not consume the next customer's room.
        $this->customer();
        $this->import(200, $template)->assertCreated();
        $this->assertDatabaseCount('gold_transactions', 2);
    }

    public function test_limit_tracks_reported_bot_stock_and_does_not_subtract_pending_sales(): void
    {
        $policy = $this->policy('standard', ['import_stock_limit_bars' => 500]);
        $bot = $this->bot(['gold_bar_qty' => 500]);
        GoldTransaction::create(['user_id' => User::factory()->create()->id, 'server_id' => $this->server->id,
            'type' => 'order', 'character_name' => 'buyer', 'gold_bar_qty' => 300, 'gold_bar_template_id' => 457,
            'gold_qty' => 300 * 37_000_000, 'pure_gold_qty' => 0, 'amount_vnd' => 10000,
            'price_at_transaction' => 3700, 'status' => 'pending']);
        $this->customer();
        $this->import(1)->assertUnprocessable();
        $bot->update(['gold_bar_qty' => 499]);
        $this->import(2)->assertUnprocessable();
        $this->import(1)->assertCreated();
        $this->customer();
        $policy->update(['import_stock_limit_bars' => 0]);
        $this->import(1)->assertUnprocessable();
        $policy->update(['import_stock_limit_bars' => null]);
        $this->import(999)->assertCreated();
    }

    public function test_switches_are_independent_per_direction_asset_and_server(): void
    {
        $this->policy('nrj', ['sell_enabled' => false]);
        $this->policy('standard', ['import_enabled' => false]);
        $user = $this->customer();
        $buy = ['server_id' => $this->server->id, 'character_name' => 'buyer', 'money_amount' => 100000,
            'gold_type' => 'bar', 'gold_bar_template_id' => 2032];
        $this->postJson('/api/orders', $buy)->assertUnprocessable();
        $this->assertSame(1_000_000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
        $this->import(1)->assertUnprocessable();
        $this->import(1, 2032)->assertCreated();
        $this->postJson('/api/orders', [...$buy, 'gold_bar_template_id' => 457])->assertCreated();

        $other = Server::create(['name' => 'other-server', 'status' => true]);
        GoldPrice::create(['server_id' => $other->id, 'price' => 3700, 'import_price' => 1000, 'status' => true]);
        $this->postJson('/api/orders', [...$buy, 'server_id' => $other->id])->assertCreated();
    }

    public function test_pure_gold_has_its_own_import_switch_and_is_not_counted_as_bars(): void
    {
        $this->policy('standard', ['import_enabled' => false, 'import_stock_limit_bars' => 0]);
        $this->customer();
        $this->import(0, 457, 50_000_000)->assertCreated();
        $this->customer();
        $this->policy('pure', ['import_enabled' => false]);
        $this->import(0, 457, 50_000_000)->assertUnprocessable()->assertJsonValidationErrors('pure_gold_qty');
        $this->assertDatabaseCount('gold_transactions', 1);
    }

    public function test_pure_sales_require_opt_in_and_use_existing_tool_terms_without_bar_conversion(): void
    {
        $user = $this->customer();
        $request = ['server_id' => $this->server->id, 'character_name' => 'buyer', 'money_amount' => 7500, 'gold_type' => 'pure'];
        $this->postJson('/api/orders', $request)->assertUnprocessable();
        $this->assertDatabaseCount('gold_transactions', 0);
        $this->policy('pure');
        $this->postJson('/api/orders', $request)->assertCreated()
            ->assertJsonPath('data.gold_bars', 0)->assertJsonPath('data.pure_gold', 27_750_000)
            ->assertJsonPath('data.charged_amount', 7500)->assertJsonPath('data.gold_type', 'pure');
        $this->assertSame(992500, (int) $user->refresh()->balance);
        $order = GoldTransaction::sole();
        $this->assertSame(27_750_000, $order->pure_gold_qty);

        $login = (int) \Illuminate\Support\Facades\DB::table('server_game_login')->max('id') + 1;
        \Illuminate\Support\Facades\DB::table('server_game_login')->insert([
            'id' => $login, 'name' => 'test', 'ip' => 'test.invalid', 'port' => '14445',
        ]);
        $bot = $this->bot(['type' => 'selling_main', 'server_game_id' => $login, 'gold_qty' => 100_000_000]);
        $service = app(GoldTradingService::class);
        $service->updateOrder($order->id, ['action' => 'start', 'bot_id' => $bot->id, 'character_id' => 1234]);
        // Existing orders must keep running after the admin switches off new purchases.
        GoldTradingPolicy::where('asset_type', 'pure')->update(['sell_enabled' => false]);
        $result = $service->updateOrder($order->id, ['action' => 'receipt', 'bot_id' => $bot->id,
            'step_id' => (string) Str::uuid(), 'bars_qty' => 0, 'pure_gold_qty' => 27_750_000, 'completed' => true,
            'bot_snapshot' => ['stock_version' => 1, 'gold_qty' => 72_250_000, 'gold_bar_qty' => 0],
            'evidence' => ['kind' => 'sale', 'confirmed' => true]]);
        $this->assertSame('completed', $result->status);
        $this->assertSame(72_250_000, $bot->refresh()->gold_qty);
        $this->assertSame(992500, (int) $user->refresh()->balance);
    }

    public function test_pure_order_above_trade_capacity_is_rejected_before_debit(): void
    {
        $this->policy('pure');
        $user = $this->customer();
        $this->postJson('/api/orders', ['server_id' => $this->server->id, 'character_name' => 'buyer',
            'money_amount' => 600000, 'gold_type' => 'pure'])->assertUnprocessable()->assertJsonValidationErrors('money_amount');
        $this->assertSame(1_000_000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('gold_transactions', 0);
    }

    private function policyPayload(): array
    {
        return ['policies' => [
            ['asset_type' => 'pure', 'sell_enabled' => true, 'import_enabled' => false, 'import_stock_limit_bars' => null],
            ['asset_type' => 'standard', 'sell_enabled' => false, 'import_enabled' => true, 'import_stock_limit_bars' => 500],
            ['asset_type' => 'nrj', 'sell_enabled' => true, 'import_enabled' => true, 'import_stock_limit_bars' => null],
        ]];
    }

    public function test_admin_can_save_per_server_policy_and_cached_public_options_refresh(): void
    {
        $this->getJson('/api/server-prices')->assertOk()->assertJsonPath('data.0.gold_trading_options.0.sell_enabled', false);
        $this->getJson('/api/servers')->assertOk()->assertJsonPath('data.0.gold_trading_options.0.sell_enabled', false);
        $admin = User::factory()->create();
        Role::findOrCreate('super-admin', 'web');
        $admin->assignRole('super-admin');
        $path = '/admin/gold-prices/servers/'.$this->server->id.'/trading-policy';
        $this->actingAs($admin)->from('/admin/gold-prices')->put($path, $this->policyPayload())
            ->assertRedirect('/admin/gold-prices')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('gold_trading_policies', 3);
        $this->assertDatabaseHas('gold_trading_policies', ['server_id' => $this->server->id,
            'asset_type' => 'standard', 'sell_enabled' => false, 'import_stock_limit_bars' => 500]);
        $this->getJson('/api/server-prices')->assertOk()->assertJsonPath('data.0.gold_trading_options.0.sell_enabled', true)
            ->assertJsonPath('data.0.gold_trading_options.1.sell_enabled', false);
        $this->getJson('/api/servers')->assertOk()->assertJsonPath('data.0.gold_trading_options.0.sell_enabled', true);
        $this->actingAs($admin)->put($path, $this->policyPayload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('gold_trading_policies', 3);
        $this->assertSame(3700, (int) GoldPrice::sole()->price);
        $this->get('/admin/gold-prices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/GoldPrices/Index')->has('tradingPolicies', 3)->has('tradingAssets', 4));
    }

    public function test_read_only_admin_cannot_change_policy_and_invalid_config_is_atomic(): void
    {
        $admin = User::factory()->create();
        $role = Role::findOrCreate('admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('gold-prices.view', 'web'));
        $admin->assignRole($role);
        $path = '/admin/gold-prices/servers/'.$this->server->id.'/trading-policy';
        $this->actingAs($admin)->put($path, $this->policyPayload())->assertForbidden();
        Role::findOrCreate('super-admin', 'web');
        $admin->assignRole('super-admin');
        $payload = $this->policyPayload();
        $payload['policies'][2]['import_stock_limit_bars'] = -1;
        $this->actingAs($admin)->put($path, $payload)->assertSessionHasErrors('policies.2.import_stock_limit_bars');
        $this->assertDatabaseCount('gold_trading_policies', 0);
        $payload = $this->policyPayload();
        $payload['policies'][2]['asset_type'] = 'standard';
        $this->put($path, $payload)->assertSessionHasErrors('policies.2.asset_type');
        $this->assertDatabaseCount('gold_trading_policies', 0);
    }
}
