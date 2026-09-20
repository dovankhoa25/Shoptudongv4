<?php
namespace Tests\Feature;
use App\Models\NroAccount;
use App\Models\User;
use App\Services\NroShopService;
use App\Services\NroSnapshotService;
use App\Services\NroListingStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NroPackageStockTest extends TestCase
{
    use RefreshDatabase;
    private User $seller;
    private NroAccount $account;
    protected function setUp(): void
    {
        parent::setUp(); $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Role::findOrCreate('ctv','web'); Role::findOrCreate('admin','web');
        $this->seller=User::factory()->create(['balance'=>0]); $this->seller->assignRole('ctv'); $this->seller->givePermissionTo(['nro-accounts.manage','item-listings.manage','item-listings.view','item-orders.view']);
        DB::table('servers')->insert(['id'=>10,'name'=>'sv2','name_view'=>'Vũ Trụ 2','status'=>true]);
        DB::table('server_game_login')->insert(['id'=>37,'name'=>'Test','ip'=>'127.0.0.1','port'=>'14445']);
        DB::table('nro_worker_keys')->insert(['name'=>'worker','token_hash'=>hash('sha256','test'),'accepts_delivery'=>true,'last_used_at'=>now()]);
        $this->account=NroAccount::create(['user_id'=>$this->seller->id,'account_name'=>'warehouse','game_password'=>'not-public','server'=>'vt2','server_index'=>1,'server_id'=>10,'server_game_id'=>37,'usage_type'=>'warehouse','status'=>'active']);
        $this->snapshot(100);
    }
    private function snapshot(int $count, bool $complete=true, ?string $captured=null): void
    {
        app(NroSnapshotService::class)->ingest($this->account,['schemaVersion'=>1,'catalogVersion'=>'17',
            'completeness'=>['bag'=>true,'chest'=>$complete,'equipped'=>true],
            'snapshot'=>['capturedAt'=>$captured ?? now()->toIso8601String(),'character'=>['id'=>1,'name'=>'warehouse','gender'=>0],
                'bag'=>$count ? [['slot'=>0,'templateId'=>223,'quantity'=>$count,'options'=>[]]]:[], 'chest'=>[], 'equipped'=>[], 'collectionChest'=>[]]]);
    }
    private function listing(string $mode='fixed',int $count=10,int $per=1): int
    {
        $id=DB::table('nro_inventory_items')->where('account_id',$this->account->id)->where('template_id',223)->value('id');
        return $this->actingAs($this->seller,'web')->postJson('/admin/nro-shop/accounts/'.$this->account->id.'/listings',[
            'price'=>1000,'stockMode'=>$mode,'packageCount'=>$count,'description'=>'Đá Titan nhận trong game','items'=>[['id'=>$id,'quantity'=>$per]],
        ])->assertOk()->json('id');
    }
    private function available(int $id): int { return app(NroShopService::class)->listing(DB::table('item_listings')->find($id))['available']; }
    private function buy(int $id, int $quantity=1, ?User $buyer=null, ?string $key=null): int
    {
        return app(NroShopService::class)->purchase($buyer ?? User::factory()->create(['balance'=>100000]),$id,'',10,$key ?? (string)Str::uuid(),$quantity,1000);
    }
    public function test_multiple_buyers_debit_totals_and_keep_unbought_packages_available(): void
    {
        $id=$this->listing('fixed',10,5); $buyer=User::factory()->create(['balance'=>100000]);$key=(string)Str::uuid();
        $order=$this->buy($id,3,$buyer,$key); $this->assertSame($order,$this->buy($id,3,$buyer,$key));
        $this->assertEquals(97000,$buyer->fresh()->balance); $this->assertSame(7,$this->available($id));
        $this->assertDatabaseHas('item_orders',['id'=>$order,'package_quantity'=>3,'unit_price'=>1000,'price'=>3000]);
        $this->assertDatabaseHas('item_order_items',['order_id'=>$order,'quantity'=>15]);
        $this->buy($id,7); $this->assertSame(0,$this->available($id));
        $this->assertEquals(50,DB::table('nro_inventory_items')->where('account_id',$this->account->id)->sum('reserved'));
        try { $this->buy($id,1); $this->fail('oversold'); } catch(ValidationException $e) { $this->assertCount(1,$e->errors()); }
        $this->assertDatabaseCount('item_orders',2);
    }
    public function test_changed_quantity_or_price_cannot_reuse_a_purchase_key(): void
    {
        $id=$this->listing(); $buyer=User::factory()->create(['balance'=>100000]); $key=(string)Str::uuid(); $this->buy($id,2,$buyer,$key);
        foreach([[3,1000],[2,1500]] as [$quantity,$price]) {
            try { app(NroShopService::class)->purchase($buyer,$id,'',10,$key,$quantity,$price); $this->fail('key reused'); } catch(ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->actingAs($this->seller,'web')->patchJson('/admin/nro-shop/listings/'.$id,['price'=>1500])->assertOk();
        try { $this->buy($id); $this->fail('old price'); } catch(ValidationException $e) { $this->assertStringContainsString('Giá đã thay đổi',$e->getMessage()); }
        $this->assertEquals(98000,$buyer->fresh()->balance);
    }
    public function test_auto_recomputes_stock_excluding_fixed_allocations_and_holds(): void
    {
        $fixed=$this->listing('fixed',2,10);$auto=$this->listing('auto',1,5);
        $this->assertSame(16,$this->available($auto)); $this->buy($auto,3); $this->assertSame(13,$this->available($auto));
        $this->snapshot(150); $this->assertSame(23,$this->available($auto)); $this->assertSame(2,$this->available($fixed));
        $this->snapshot(150); $this->assertSame(23,$this->available($auto));
        $this->snapshot(999,true,now()->subDay()->toIso8601String()); $this->assertSame(23,$this->available($auto));
        $this->snapshot(999,false); $this->assertSame(0,$this->available($auto));
        $this->snapshot(150); $this->assertSame(23,$this->available($auto));
    }
    public function test_auto_overlap_pause_withdraw_and_hidden_warehouse(): void
    {
        $id=$this->listing('auto',1,10); $this->buy($id,10); $this->assertSame(0,$this->available($id));
        $this->snapshot(150); $this->assertSame(5,$this->available($id));
        $this->actingAs($this->seller,'web')->patchJson('/admin/nro-shop/listings/'.$id,['status'=>'paused'])->assertOk();
        $this->snapshot(200); $this->assertSame(0,$this->available($id));
        $item=DB::table('nro_inventory_items')->first(); $this->assertSame(0,NroListingStock::selectable($item,NroListingStock::allocated($this->account->id)));
        $body=['price'=>1,'stockMode'=>'auto','items'=>[['id'=>$item->id,'quantity'=>1]]];
        $this->postJson('/admin/nro-shop/accounts/'.$this->account->id.'/listings',$body)->assertUnprocessable();
        $this->patchJson('/admin/nro-shop/listings/'.$id,['status'=>'archived'])->assertOk();
        $next=$this->listing('auto'); $this->account->update(['shop_hidden'=>true]);
        $this->snapshot(250);$this->assertSame(0,$this->available($next));
        $this->account->update(['shop_hidden'=>false]);$this->assertSame(150,$this->available($next));
    }
    public function test_refund_restores_quota_once_but_waits_for_confirmed_stock(): void
    {
        $id=$this->listing('fixed',3,5);$buyer=User::factory()->create(['balance'=>100000]);$order=$this->buy($id,2,$buyer);
        $admin=User::factory()->create();$admin->assignRole('admin');$admin->givePermissionTo(['nro-accounts.manage','item-listings.manage']);
        app(\App\Services\NroOrderRefund::class)->run($order,$admin,1200,'Hoàn theo quyết định của quản trị viên');
        app(\App\Services\NroOrderRefund::class)->run($order,$admin,1200,'Hoàn theo quyết định của quản trị viên');
        $this->assertEquals(99200,$buyer->fresh()->balance);$this->assertSame(0,$this->available($id));
        $this->assertDatabaseHas('item_listings',['id'=>$id,'packages_remaining'=>3]);
        $this->snapshot(0);$this->assertSame(0,$this->available($id));$this->snapshot(100);$this->assertSame(3,$this->available($id));
    }
    public function test_seller_deny_wins_and_old_orders_stay_deliverable(): void
    {
        $id=$this->listing();$order=$this->buy($id);$admin=User::factory()->create();$admin->assignRole('admin');$admin->givePermissionTo(['nro-accounts.manage','item-listings.manage']);
        $url='/admin/nro-shop/accounts/'.$this->account->id.'/seller-policy';
        $this->actingAs($this->seller,'web')->patchJson($url,['sellingEnabled'=>true,'allowIds'=>[],'denyIds'=>[]])->assertForbidden();
        $this->actingAs($admin,'web')->patchJson($url,['sellingEnabled'=>true,'allowIds'=>[223],'denyIds'=>[223]])->assertOk();
        $this->assertSame(0,$this->available($id));
        $this->getJson('/admin/nro-shop/accounts/'.$this->account->id)->assertOk()->assertJsonCount(0,'inventory');
        $this->getJson('/api/nro-shop/listings')->assertJsonCount(0,'data');
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'awaiting_receipt']);
        $this->patchJson($url,['sellingEnabled'=>true,'allowIds'=>null,'denyIds'=>[]])->assertOk();$this->assertSame(9,$this->available($id));
        $this->patchJson('/admin/nro-shop/listings/'.$id,['status'=>'paused'])->assertOk();
        $this->patchJson($url,['sellingEnabled'=>false,'allowIds'=>[],'denyIds'=>[]])->assertOk();
        $this->patchJson($url,['sellingEnabled'=>true,'allowIds'=>[],'denyIds'=>[]])->assertOk();$this->assertSame(0,$this->available($id));
    }
    public function test_equipment_cannot_be_repeated_and_costumes_never_enter_sale_picker(): void
    {
        $item=DB::table('nro_inventory_items')->first();
        foreach([0,5] as $type) {
            $data=json_decode($item->item_json,true);$data['type']=$type;DB::table('nro_inventory_items')->where('id',$item->id)->update(['item_json'=>json_encode($data)]);
            $this->actingAs($this->seller,'web')->postJson('/admin/nro-shop/accounts/'.$this->account->id.'/listings',[
                'price'=>1000,'stockMode'=>'auto','items'=>[['id'=>$item->id,'quantity'=>1]],
            ])->assertUnprocessable();
        }
        $this->getJson('/admin/nro-shop/accounts/'.$this->account->id)->assertJsonCount(0,'inventory');
    }
    public function test_admin_search_tokens_remain_owner_scoped(): void
    {
        $id=$this->listing();$other=User::factory()->create();$other->assignRole('ctv');$other->givePermissionTo(['item-listings.view']);
        $term='#goi:'.$id.' #tk:'.$this->account->id.' #ctv:'.$this->seller->username.' #vp:223';
        $url='/admin/nro-shop/listings?'.http_build_query(['q'=>$term]);
        $this->actingAs($this->seller,'web')->getJson($url)->assertOk()->assertJsonCount(1,'data');
        $this->actingAs($other,'web')->getJson($url)->assertOk()->assertJsonCount(0,'data');
    }
    public function test_public_description_is_explicit_and_stock_cache_invalidates_after_purchase(): void
    {
        $id=$this->listing();$this->getJson('/api/nro-shop/listings')->assertJsonPath('data.0.available',10)->assertJsonPath('data.0.description','Đá Titan nhận trong game');
        Passport::actingAs(User::factory()->create(['balance'=>100000]),['profile:read','profile:write']);
        $this->postJson('/api/nro-shop/orders',['listingId'=>$id,'serverId'=>10,'requestKey'=>(string)Str::uuid(),'packageQuantity'=>4,'expectedPrice'=>1000])->assertOk()->assertJsonPath('data.packageQuantity',4);
        $this->getJson('/api/nro-shop/listings')->assertJsonPath('data.0.available',6);
    }

    public function test_combo_uses_scarcest_component_and_multiplies_every_order_line(): void
    {
        app(NroSnapshotService::class)->ingest($this->account,['schemaVersion'=>1,'catalogVersion'=>'17',
            'completeness'=>['bag'=>true,'chest'=>true,'equipped'=>true],
            'snapshot'=>['capturedAt'=>now()->toIso8601String(),'character'=>['id'=>1,'name'=>'warehouse','gender'=>0],
                'bag'=>[['slot'=>0,'templateId'=>223,'quantity'=>100,'options'=>[]],['slot'=>1,'templateId'=>224,'quantity'=>13,'options'=>[]]],'chest'=>[],'equipped'=>[],'collectionChest'=>[]]]);
        $items=DB::table('nro_inventory_items')->where('account_id',$this->account->id)->pluck('id','template_id');
        $id=$this->actingAs($this->seller,'web')->postJson('/admin/nro-shop/accounts/'.$this->account->id.'/listings',[
            'price'=>1000,'stockMode'=>'auto','items'=>[['id'=>$items[223],'quantity'=>5],['id'=>$items[224],'quantity'=>2]],
        ])->assertOk()->json('id');
        $this->assertSame(6,$this->available($id)); $order=$this->buy($id,3);
        $this->assertDatabaseHas('item_order_items',['order_id'=>$order,'inventory_item_id'=>$items[223],'quantity'=>15]);
        $this->assertDatabaseHas('item_order_items',['order_id'=>$order,'inventory_item_id'=>$items[224],'quantity'=>6]);
        $this->assertSame(3,$this->available($id));
    }
    public function test_insufficient_balance_rolls_back_all_stock_and_order_changes(): void
    {
        $id=$this->listing();$buyer=User::factory()->create(['balance'=>1999]);
        try {$this->buy($id,2,$buyer);$this->fail('insufficient money');} catch(ValidationException $e) {$this->assertNotEmpty($e->errors());}
        $this->assertDatabaseCount('item_orders',0);$this->assertDatabaseCount('item_inventory_reservations',0);
        $this->assertSame(10,$this->available($id));$this->assertEquals(1999,$buyer->fresh()->balance);
    }
    public function test_seller_can_clear_unsold_quota_without_changing_existing_orders(): void
    {
        $id=$this->listing();$order=$this->buy($id,2);
        $this->actingAs($this->seller,'web')->patchJson('/admin/nro-shop/listings/'.$id,['packageCount'=>0])->assertOk();
        $this->assertSame(0,$this->available($id));$this->assertDatabaseHas('item_listings',['id'=>$id,'status'=>'sold','packages_remaining'=>0]);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'awaiting_receipt','package_quantity'=>2]);
        $this->patchJson('/admin/nro-shop/listings/'.$id,['status'=>'active','packageCount'=>5])->assertOk();
        $this->assertSame(5,$this->available($id));
    }
    public function test_migration_can_rerun_without_resetting_live_quota_or_order_price(): void
    {
        $id=$this->listing();$order=$this->buy($id,3);
        $migration=require database_path('migrations/2026_09_21_000001_add_nro_package_stock_and_seller_policies.php');
        $migration->up(); $migration->up();
        $this->assertSame(7,$this->available($id));
        $this->assertDatabaseHas('item_orders',['id'=>$order,'price'=>3000,'unit_price'=>1000,'package_quantity'=>3]);
    }
    public function test_refunding_archived_listing_never_reopens_it(): void
    {
        $id=$this->listing();$order=$this->buy($id,2);
        $this->actingAs($this->seller,'web')->patchJson('/admin/nro-shop/listings/'.$id,['status'=>'archived'])->assertOk();
        $admin=User::factory()->create();$admin->assignRole('admin');$admin->givePermissionTo(['nro-accounts.manage','item-listings.manage']);
        app(\App\Services\NroOrderRefund::class)->run($order,$admin,2000,'Hoàn đơn chưa giao');
        $this->snapshot(100);$this->assertSame(0,$this->available($id));
        $this->assertDatabaseHas('item_listings',['id'=>$id,'status'=>'archived']);
    }
}
