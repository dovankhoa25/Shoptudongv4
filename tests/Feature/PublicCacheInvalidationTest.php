<?php
namespace Tests\Feature;

use App\Http\Controllers\Api\HomeController;
use App\Models\{Server, GoldPrice, GemPrice, GemBot, Bot, Category, GameType, Service};
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Tests\TestCase;

class PublicCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_price_cache_has_constant_cold_queries_and_no_warm_queries_and_tracks_inventory(): void
    {
        Cache::flush();
        for ($i = 0; $i < 12; $i++) {
            $server = Server::create(['name' => 'Server '.$i, 'status' => true]);
            GoldPrice::create(['server_id' => $server->id, 'price' => 100, 'import_price' => 90, 'status' => true]);
            GemPrice::create(['server_id' => $server->id, 'multiplier' => 15, 'min_amount' => 10000, 'status' => true]);
            $bot = GemBot::create(['name' => 'Bot', 'account_name' => 'private', 'account_password' => 'secret',
                'server_id' => $server->id, 'server_game_id' => 1, 'gem_qty' => 50, 'status' => true,
                'map_id' => '1', 'map_name' => 'Kame', 'area_number' => '0']);
        }
        DB::enableQueryLog();
        $first = app(HomeController::class)->getServerPrices();
        $this->assertCount(12, $first['data']);
        $this->assertCount(3, DB::getQueryLog());
        $this->assertSame(50, $first['data'][11]['total_available_gems']);
        DB::flushQueryLog();
        $this->assertSame($first, app(HomeController::class)->getServerPrices());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $bot->syncFromApp(80);
        $this->assertSame(80, app(HomeController::class)->getServerPrices()['data'][11]['total_available_gems']);
        GoldPrice::where('server_id', $server->id)->first()->update(['price' => 200]);
        $this->assertEquals(200, app(HomeController::class)->getServerPriceById($server->id)['data']['gold_sell_price']);
    }

    public function test_catalog_pivot_and_template_mutations_invalidate_rendered_payload(): void
    {
        $game = GameType::create(['name' => 'Game']);
        $category = Category::create(['name' => 'Services', 'game_type_id' => $game->id, 'template' => 'service']);
        $service = Service::create(['name' => 'Service', 'default_price' => 100, 'status' => true]);
        $controller = app(\App\Http\Controllers\Api\CategoryController::class);
        $this->assertSame([], $controller->servicesBySlug($category->slug)['services']);
        app(\App\Http\Controllers\Admin\CategoryServiceController::class)->assign(
            Request::create('/', 'POST', ['category_id' => $category->id, 'service_ids' => [$service->id]])
        );
        $this->assertCount(1, $controller->servicesBySlug($category->slug)['services']);
        $service->update(['default_price' => 250]);
        $this->assertEquals(250, $controller->servicesBySlug($category->slug)['services'][0]['default_price']);
    }

    public function test_bot_public_fields_invalidate_but_gold_inventory_only_updates_do_not(): void
    {
        $server = Server::create(['name' => 'Server', 'status' => true]);
        $bot = Bot::create(['name' => 'Bot', 'account_name' => 'private', 'account_password' => 'secret',
            'server_id' => $server->id, 'server_game_id' => 1, 'type' => 'selling_main', 'status' => true,
            'map_id' => '1', 'map_name' => 'Kame', 'area_number' => '0']);
        $controller = app(\App\Http\Controllers\Api\BotController::class);
        $request = Request::create('/', 'GET', ['server_id' => $server->id, 'type' => 'selling_main']);
        $first = $controller->index($request);
        $this->assertArrayNotHasKey('account_password', $first['data'][0]);
        $bot->update(['gold_qty' => 100]);
        DB::enableQueryLog();
        $this->assertSame($first, $controller->index($request));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $bot->update(['map_name' => 'New map']);
        $this->assertSame('New map', $controller->index($request)['data'][0]['map_name']);
    }
}
