<?php

namespace Tests\Feature;

use App\Events\UserEvent;
use App\Models\{GemBot, GemPrice, GemTransaction, Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GemTicketPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trading_v3.enabled' => true]);
        Event::fake([UserEvent::class]);
    }

    #[DataProvider('ticketPrices')]
    public function test_checkout_selects_ticket_price_or_fallback_and_snapshots_it(?float $ticketPrice, int $gems, string $savedPrice): void
    {
        $server = $this->server();
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => $ticketPrice, 'status' => true]);
        $bot = $this->bot($server, 1000, 100);
        $user = $this->customer();
        $this->postJson('/api/gem/orders', $this->payload($server))
            ->assertCreated()->assertJsonPath('data.gem_qty', $gems)
            ->assertJsonPath('data.multiplier', $savedPrice)->assertJsonPath('data.bot_id', $bot->id);
        $order = GemTransaction::firstOrFail();
        $this->assertSame($savedPrice, $order->price_at_transaction);
        $this->assertSame(90000, (int) $user->fresh()->balance);
        $this->assertSame($gems, (int) $order->gem_qty);
    }

    public static function ticketPrices(): array
    {
        return ['unset-fallback' => [null, 100, '10.00'], 'cheaper-ticket' => [12, 120, '12.00']];
    }

    public function test_consignment_rounds_down_before_applying_20000_limit(): void
    {
        $server = $this->server();
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 20, 'status' => true]);
        $user = $this->customer(5000000);
        $payload = $this->payload($server, ['delivery_method' => 'consignment', 'money_amount' => 10001]);
        $this->postJson('/api/gem/orders', $payload)->assertCreated()
            ->assertJsonPath('data.gem_qty', 100)->assertJsonPath('data.multiplier', '10');
        $payload['character_name'] = 'buyer2';
        $payload['money_amount'] = 2000001;
        $this->postJson('/api/gem/orders', $payload)->assertCreated()
            ->assertJsonPath('data.gem_qty', 20000);
        $payload['character_name'] = 'buyer3';
        $payload['money_amount'] = 2000100;
        $this->postJson('/api/gem/orders', $payload)->assertUnprocessable();
        $this->assertSame(2989998, (int) $user->fresh()->balance);
        $this->assertDatabaseCount('gem_transactions', 2);
    }

    public function test_ticket_fractional_gems_keep_existing_upward_rounding(): void
    {
        $server = $this->server();
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 9.99, 'status' => true]);
        $this->bot($server, 1000, 100);
        $user = $this->customer();
        $this->postJson('/api/gem/orders', $this->payload($server, ['money_amount' => 10001]))
            ->assertCreated()->assertJsonPath('data.gem_qty', 100)->assertJsonPath('data.multiplier', '9.99');
        $this->assertSame(89999, (int) $user->fresh()->balance);
        $this->assertSame(100, (int) GemTransaction::firstOrFail()->gem_qty);
    }

    public function test_ticket_rounding_must_be_multiple_of_ten_before_any_debit(): void
    {
        $server = $this->server();
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 12.35, 'status' => true]);
        $this->bot($server, 1000, 100);
        $user = $this->customer();
        $this->postJson('/api/gem/orders', $this->payload($server))->assertUnprocessable();
        $this->assertSame(100000, (int) $user->fresh()->balance);
        $this->assertDatabaseCount('gem_transactions', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_ticket_price_snapshot_retains_two_decimal_places(): void
    {
        $server = $this->server();
        $price = GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 12.35, 'status' => true]);
        $this->bot($server, 10000, 1000);
        $this->customer(300000);
        $this->postJson('/api/gem/orders', $this->payload($server, ['money_amount' => 200000]))
            ->assertCreated()->assertJsonPath('data.gem_qty', 2470)->assertJsonPath('data.multiplier', '12.35');
        $this->assertSame('12.35', GemTransaction::firstOrFail()->price_at_transaction);
        $this->assertEquals(12.35, \Illuminate\Support\Facades\DB::table('gem_transactions')->value('price_at_transaction'));
        $price->update(['gift_ticket_multiplier' => 20]);
        $this->assertSame('12.35', GemTransaction::firstOrFail()->price_at_transaction);
        $this->assertSame(2470, (int) GemTransaction::firstOrFail()->gem_qty);
    }

    public function test_ticket_orders_over_20000_use_one_bot_stock_limit(): void
    {
        $server = $this->server();
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 20, 'status' => true]);
        $bot = $this->bot($server, 30000, 3000);
        $user = $this->customer(5000000);
        $this->postJson('/api/gem/orders', $this->payload($server, ['money_amount' => 1500000]))
            ->assertCreated()->assertJsonPath('data.gem_qty', 30000)->assertJsonPath('data.bot_id', $bot->id);
        $this->assertSame(3500000, (int) $user->fresh()->balance);
        $this->assertSame(0, \App\Services\TradingV3\GemStock::giftCapacity($bot->fresh()));
        $this->postJson('/api/gem/orders', $this->payload($server, ['character_name' => 'buyer2']))->assertConflict();
        $this->assertSame(3500000, (int) $user->fresh()->balance);
        $this->assertDatabaseCount('gem_transactions', 1);
    }

    public function test_tickets_cannot_combine_multiple_bot_capacity_for_one_order(): void
    {
        $server = $this->server();
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 20, 'status' => true]);
        $this->bot($server, 15000, 1500);
        $this->bot($server, 15000, 1500);
        $user = $this->customer(5000000);
        $this->postJson('/api/gem/orders', $this->payload($server, ['money_amount' => 1500000]))->assertConflict();
        $this->assertSame(5000000, (int) $user->fresh()->balance);
        $this->assertDatabaseCount('gem_transactions', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_public_resources_refresh_configured_price_and_cleared_fallback(): void
    {
        $server = $this->server();
        $price = GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'status' => true]);
        Server::create(['name' => 'unconfigured', 'status' => true]);
        $this->getJson('/api/gem/servers')->assertOk()
            ->assertJsonPath('data.0.gem_prices.0.gift_ticket_multiplier', null)
            ->assertJsonPath('data.0.gem_prices.0.gift_ticket_gems_per_10k', 100);
        $this->getJson('/api/server-prices')->assertOk()
            ->assertJsonPath('data.0.gem_gift_ticket_multiplier', 10)
            ->assertJsonPath('data.1.gem_gift_ticket_multiplier', null);
        $price->update(['gift_ticket_multiplier' => 12.35]);
        $this->getJson('/api/gem/servers')->assertOk()
            ->assertJsonPath('data.0.gem_prices.0.gift_ticket_multiplier', '12.35')
            ->assertJsonPath('data.0.gem_prices.0.gift_ticket_gems_per_10k', 123.5);
        $this->getJson('/api/server-prices/'.$server->id)->assertOk()
            ->assertJsonPath('data.gem_gift_ticket_multiplier', 12.35);
        $price->update(['gift_ticket_multiplier' => null]);
        $this->getJson('/api/server-prices')->assertOk()->assertJsonPath('data.0.gem_gift_ticket_multiplier', 10);
        $this->getJson('/api/server-prices/'.$server->id)->assertOk()->assertJsonPath('data.gem_gift_ticket_multiplier', 10);
    }

    public function test_admin_can_set_clear_and_preserve_ticket_price_on_bulk_updates(): void
    {
        $server = $this->server();
        $this->admin();
        $payload = ['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 12.35, 'status' => true];
        $this->post('/admin/gem-prices', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $price = GemPrice::getCurrentMultiplier($server->id);
        $this->assertSame('12.35', $price->gift_ticket_multiplier);
        $resource = (new \App\Http\Resources\GemPrice\GemPriceResource($price))->resolve();
        $this->assertSame('12.35', $resource['gift_ticket_multiplier']);
        $this->assertSame(123.5, $resource['gift_ticket_gems_per_10k']);
        $this->postJson('/admin/gem-prices/bulk-update', ['updates' => [['server_id' => $server->id, 'multiplier' => 11]]])->assertOk();
        $price = GemPrice::getCurrentMultiplier($server->id);
        $this->assertSame('12.35', $price->gift_ticket_multiplier);
        $this->postJson('/admin/gem-prices/bulk-update', ['updates' => [['server_id' => $server->id, 'multiplier' => 12, 'gift_ticket_multiplier' => '']]])->assertOk();
        $price = GemPrice::getCurrentMultiplier($server->id);
        $this->assertNull($price->gift_ticket_multiplier);
        $this->assertSame('12.00', $price->multiplierFor('gift_ticket'));
        $payload['gift_ticket_multiplier'] = 15;
        $this->put('/admin/gem-prices/'.$price->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        unset($payload['gift_ticket_multiplier']);
        $this->put('/admin/gem-prices/'.$price->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('15.00', $price->fresh()->gift_ticket_multiplier);
        $payload['gift_ticket_multiplier'] = '';
        $this->put('/admin/gem-prices/'.$price->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($price->fresh()->gift_ticket_multiplier);
    }

    #[DataProvider('invalidPrices')]
    public function test_admin_rejects_invalid_ticket_prices_without_changing_existing_price($invalid): void
    {
        $server = $this->server();
        $this->admin();
        $price = GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 12, 'status' => true]);
        $this->putJson('/admin/gem-prices/'.$price->id, [
            'server_id' => $server->id, 'multiplier' => 10, 'status' => true, 'gift_ticket_multiplier' => $invalid,
        ])->assertUnprocessable()->assertJsonValidationErrors('gift_ticket_multiplier');
        $this->assertSame('12.00', $price->fresh()->gift_ticket_multiplier);
    }

    public static function invalidPrices(): array
    {
        return ['zero' => [0], 'too-low' => [0.99], 'too-high' => [100.01], 'precision' => [12.345], 'text' => ['abc']];
    }

    public function test_admin_calculator_selects_ticket_price_and_matches_gift_rounding(): void
    {
        $server = $this->server();
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 10, 'gift_ticket_multiplier' => 9.99, 'status' => true]);
        $this->admin();
        $payload = ['server_id' => $server->id, 'vnd_amount' => 10001, 'delivery_method' => 'gift_ticket'];
        $this->postJson('/admin/gem-prices/api/calculate', $payload)->assertOk()->assertJsonPath('gems', 100)->assertJsonPath('multiplier', '9.99');
        unset($payload['delivery_method']);
        $this->postJson('/admin/gem-prices/api/calculate', $payload)->assertOk()->assertJsonPath('gems', 100)->assertJsonPath('multiplier', '10.00');
    }

    private function server(): Server
    {
        return Server::create(['name' => 'server-test', 'status' => true]);
    }

    private function bot(Server $server, int $gems, int $tickets): GemBot
    {
        return GemBot::create([
            'server_id' => $server->id, 'name' => 'gem-test', 'account_name' => 'fixture', 'account_password' => 'not-a-real-password',
            'gem_qty' => $gems, 'gift_ticket_qty' => $tickets, 'consign_item_qty' => 1, 'status' => true,
            'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41', 'coordinates' => '652-336',
            'v3_worker_id' => '00000000-0000-4000-8000-000000000001', 'v3_lease_until' => now()->addMinute(),
        ]);
    }

    private function customer(int $balance = 100000): User
    {
        $user = User::factory()->create(['balance' => $balance]);
        Passport::actingAs($user, ['profile:read', 'profile:write']);

        return $user;
    }

    private function admin(): void
    {
        Role::findOrCreate('super-admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);
    }

    private function payload(Server $server, array $overrides = []): array
    {
        return ['server_id' => $server->id, 'character_name' => 'buyer1', 'money_amount' => 10000, 'delivery_method' => 'gift_ticket', ...$overrides];
    }
}
