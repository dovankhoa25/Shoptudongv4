<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\{BotController, GemBotController};
use App\Http\Resources\Api\ApiGemBotResource;
use App\Http\Resources\GemBots\GemBotResource;
use App\Models\{Bot, GemBot, GemTransaction, InventoryMovement, Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class AdminBotConfigInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private int $loginId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->server = Server::create(['name' => 'Config inventory test', 'status' => true]);
        $this->loginId = (int) DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert(['id' => $this->loginId, 'name' => 'Offline test', 'ip' => 'test.invalid', 'port' => '14445']);
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    private function createBot(bool $gem): Bot|GemBot
    {
        $data = ['name' => 'Before edit', 'account_name' => $gem ? 'test-gem' : 'test-gold', 'account_password' => 'test-password',
            'server_id' => $this->server->id, 'server_game_id' => $this->loginId, 'status' => true,
            'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41', 'coordinates' => '652-336'];
        return $gem ? GemBot::create([...$data, 'gem_qty' => 100, 'gift_ticket_qty' => 10, 'consign_item_qty' => 3])
            : Bot::create([...$data, 'type' => 'selling_main', 'gold_qty' => 0, 'gold_bar_qty' => 10]);
    }

    private function configRequest(Bot|GemBot $bot, array $extra = []): Request
    {
        return Request::create('/admin/test-config', 'PUT', [...$bot->only(['account_name', 'server_id', 'server_game_id', 'type', 'map_id', 'area_number', 'coordinates', 'status']), 'name' => 'Changed config', ...$extra]);
    }

    public function test_gold_config_edit_does_not_write_inventory_from_a_stale_route_model(): void
    {
        $stale = $this->createBot(false);
        Bot::whereKey($stale->id)->update(['gold_qty' => 123, 'gold_bar_qty' => 9]);
        app(BotController::class)->update($this->configRequest($stale), $stale);

        $fresh = $stale->fresh();
        $this->assertSame('Changed config', $fresh->name);
        $this->assertSame(123, (int) $fresh->gold_qty);
        $this->assertSame(9, (int) $fresh->gold_bar_qty);
        $this->assertSame('test-password', $fresh->account_password);
        $this->assertSame(0, InventoryMovement::where('movement_type', 'manual_adjustment')->count());
    }

    public function test_gem_edit_page_includes_server_context_required_by_the_form(): void
    {
        $bot = $this->createBot(true);
        app(GemBotController::class)->edit($bot);
        $data = (new GemBotResource($bot))->resolve();
        $this->assertSame($this->server->id, $data['server']['id']);
        $this->assertSame($this->server->name, $data['server']['name']);
        $this->assertSame($this->loginId, $data['server_game_id']);
    }

    public function test_gem_config_edit_does_not_write_inventory_from_a_stale_route_model(): void
    {
        $stale = $this->createBot(true);
        GemBot::whereKey($stale->id)->update(['gem_qty' => 80, 'gift_ticket_qty' => 8, 'consign_item_qty' => 2]);
        app(GemBotController::class)->update($this->configRequest($stale), $stale);

        $fresh = $stale->fresh();
        $this->assertSame('Changed config', $fresh->name);
        $this->assertSame(80, (int) $fresh->gem_qty);
        $this->assertSame(8, (int) $fresh->gift_ticket_qty);
        $this->assertSame(2, (int) $fresh->consign_item_qty);
        $this->assertSame(0, InventoryMovement::where('movement_type', 'manual_adjustment')->count());
    }

    public function test_explicit_gold_inventory_input_remains_supported_and_records_adjustment(): void
    {
        $bot = $this->createBot(false);
        app(BotController::class)->update($this->configRequest($bot, ['gold_bar_qty' => 0, 'gold_qty' => 0]), $bot);
        $this->assertSame(0, (int) $bot->fresh()->gold_bar_qty);
        $this->assertSame(1, InventoryMovement::where('movement_type', 'manual_adjustment')->count());
    }

    public function test_explicit_gem_inventory_input_remains_supported_and_records_adjustment(): void
    {
        $bot = $this->createBot(true);
        app(GemBotController::class)->update($this->configRequest($bot, ['gem_qty' => 0]), $bot);
        $this->assertSame(0, (int) $bot->fresh()->gem_qty);
        $this->assertSame(10, (int) $bot->fresh()->gift_ticket_qty);
        $this->assertSame(1, InventoryMovement::where('movement_type', 'manual_adjustment')->count());
    }

    public function test_admin_unreserved_capacity_is_independent_of_lease_and_public_capacity_keeps_lease_gate(): void
    {
        config(['trading_v3.enabled' => true]);
        $bot = $this->createBot(true);
        GemTransaction::create(['bot_id' => $bot->id, 'user_id' => $this->admin->id, 'server_id' => $this->server->id,
            'character_name' => 'test-buyer', 'delivery_method' => 'gift_ticket', 'status' => 'pending',
            'amount_vnd' => 10000, 'gem_qty' => 30, 'price_at_transaction' => 10]);

        $adminData = (new GemBotResource($bot))->resolve();
        $this->assertSame(10, $adminData['gift_ticket_qty']);
        $this->assertSame(70, $adminData['gift_unreserved_gems']);
        $this->assertSame(0, $adminData['gift_available_gems']);
        $this->assertSame(0, (new ApiGemBotResource($bot))->resolve()['gift_available_gems']);

        $bot->forceFill(['v3_worker_id' => '00000000-0000-4000-8000-000000000902', 'v3_lease_until' => now()->addMinutes(2)])->save();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $adminData = (new GemBotResource($bot))->resolve();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(70, $adminData['gift_unreserved_gems']);
        $this->assertSame(70, $adminData['gift_available_gems']);
        $this->assertCount(1, $queries);
        $this->assertSame(70, (new ApiGemBotResource($bot))->resolve()['gift_available_gems']);
    }
}
