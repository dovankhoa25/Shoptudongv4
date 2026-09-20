<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HomeController;
use App\Models\{Server, GoldPrice, GemPrice};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServerCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): array
    {
        return app(HomeController::class)->getServerPrices(Request::create('/', 'GET', ['include_inactive' => '1']));
    }

    public function test_full_catalog_includes_maintenance_and_minimum_without_changing_default_list(): void
    {
        $server = Server::create(['name' => 'Active', 'status' => true]);
        Server::create(['name' => 'Maintenance', 'status' => false]);
        GoldPrice::create(['server_id' => $server->id, 'price' => 100, 'import_price' => 90, 'status' => true]);
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 15, 'min_amount' => 20000, 'status' => true]);
        $this->assertCount(1, app(HomeController::class)->getServerPrices()['data']);
        $data = $this->catalog();
        $this->assertTrue($data['all_servers']);
        $this->assertNotEmpty($data['generated_at']);
        $this->assertCount(2, $data['data']);
        $this->assertSame(20000, $data['data'][0]['gem_min_amount']);
        $this->assertEquals(100, $data['data'][0]['gold_sell_price']);
        $this->assertNull($data['data'][1]['gem_multiplier']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($data, $this->catalog());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->getJson('/api/server-prices?include_inactive=1')->assertOk()->assertJsonPath('all_servers', true);
    }

    public function test_price_minimum_and_server_mutations_invalidate_shared_catalog(): void
    {
        $server = Server::create(['name' => 'Server', 'status' => true]);
        $gold = GoldPrice::create(['server_id' => $server->id, 'price' => 100, 'import_price' => 90, 'status' => true]);
        $gem = GemPrice::create(['server_id' => $server->id, 'multiplier' => 15, 'min_amount' => 10000, 'status' => true]);
        $this->catalog();
        $gold->update(['price' => 200, 'import_price' => 180]);
        $gem->update(['multiplier' => 20, 'min_amount' => 30000]);
        $row = $this->catalog()['data'][0];
        $this->assertEquals(200, $row['gold_sell_price']);
        $this->assertEquals(180, $row['gold_import_price']);
        $this->assertEquals(20, $row['gem_multiplier']);
        $this->assertSame(30000, $row['gem_min_amount']);
        $server->update(['status' => false]);
        $this->assertEquals(0, $this->catalog()['data'][0]['status']);
        $gem->delete();
        $this->assertNull($this->catalog()['data'][0]['gem_multiplier']);
        $gold->delete();
        $this->assertNull($this->catalog()['data'][0]['gold_sell_price']);
        $server->delete();
        $this->assertSame([], $this->catalog()['data']);
    }
}
