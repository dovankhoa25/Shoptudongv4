<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NroDemoItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_is_idempotent_filterable_and_cannot_trade(): void
    {
        $owner=User::factory()->create();
        foreach (range(1,3) as $id) DB::table('servers')->insert(['id'=>$id,'name'=>'sv'.$id,'name_view'=>'Server '.$id,'status'=>true]);
        $this->artisan('nro:demo-items',['--owner'=>$owner->id])->assertExitCode(0);
        $this->assertDatabaseCount('item_listings',400);
        $this->assertDatabaseCount('nro_inventory_items',500);
        $this->assertDatabaseCount('nro_accounts',3);
        $this->assertDatabaseCount('nro_worker_jobs',0);
        $this->assertDatabaseCount('item_orders',0);
        $this->assertSame(0,DB::table('nro_accounts')->whereNotNull('game_password')->count());
        $this->assertSame(0,DB::table('nro_accounts')->where('status','active')->count());
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonPath('total',400)->assertJsonPath('lastPage',20)
            ->assertJsonPath('data.0.available',0)->assertJsonPath('data.0.unavailableReasons.0','Dữ liệu demo · không giao dịch thật');
        $this->getJson('/api/nro-shop/listings?bundle=combo')->assertOk()->assertJsonPath('total',50);
        $this->getJson('/api/nro-shop/listings?bundle=single')->assertOk()->assertJsonPath('total',350);
        $this->assertGreaterThan(0,$this->getJson('/api/nro-shop/listings?group=equipment&minStars=2&stat=hp')->assertOk()->json('total'));
        $this->assertGreaterThan(0,$this->getJson('/api/nro-shop/listings?group=equipment&minStars=9')->assertOk()->json('total'));
        $this->assertGreaterThan(0,DB::table('nro_inventory_items')->where('quantity',500)->count());
        $this->artisan('nro:demo-items',['--owner'=>$owner->id])->assertExitCode(0);
        $this->assertDatabaseCount('nro_inventory_items',500);
        $this->assertDatabaseCount('item_listings',400);
    }

    public function test_demo_refuses_production(): void
    {
        $this->app->instance('env','production');
        $this->artisan('nro:demo-items')->assertExitCode(1);
        $this->assertDatabaseCount('nro_accounts',0);
    }
}
