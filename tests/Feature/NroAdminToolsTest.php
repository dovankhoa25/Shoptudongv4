<?php
namespace Tests\Feature;

use App\Events\NroShopUpdated;
use App\Models\NroAccount;
use App\Models\User;
use App\Services\NroAccountRegistration;
use App\Services\NroItemFilters;
use App\Services\NroCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NroAdminToolsTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp(); Cache::flush(); Event::fake([NroShopUpdated::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Role::findOrCreate('admin','web'); Role::findOrCreate('ctv','web');
        $this->admin=User::factory()->create(); $this->admin->assignRole('admin');
        $this->admin->givePermissionTo(['nro-accounts.manage','item-listings.manage','item-orders.view','nro-sale-policy.manage']);
        $this->actingAs($this->admin,'web');
        DB::table('servers')->insertOrIgnore(['id'=>10,'name'=>'test','name_view'=>'Server test','status'=>true]);
        DB::table('server_game_login')->insertOrIgnore(['id'=>37,'name'=>'test','ip'=>'127.0.0.1','port'=>'14445']);
    }
    private function account(?User $owner=null, string $usage='warehouse'): NroAccount
    {
        return NroAccount::create(['user_id'=>($owner ?? $this->admin)->id,'account_name'=>'warehouse-'.Str::random(10),
            'game_password'=>'test-only','server'=>'login37','server_index'=>0,'server_id'=>10,'server_game_id'=>37,'usage_type'=>$usage,'status'=>'active']);
    }
    private function listing(NroAccount $account, array $templates=[223], int $price=100, string $status='active'): int
    {
        $listing=DB::table('item_listings')->insertGetId(['user_id'=>$account->user_id,'account_id'=>$account->id,'title'=>'Fixture listing',
            'price'=>$price,'status'=>$status,'stock_mode'=>'fixed','packages_remaining'=>1,'created_at'=>now(),'updated_at'=>now()]);
        foreach ($templates as $template) {
            $id=is_array($template)?$template['id']:$template;
            $catalog=NroCatalog::templates()[$id] ?? [];
            $item=['templateId'=>$id,'name'=>$catalog['name'] ?? 'Unknown','type'=>$catalog['type'] ?? 99,'quantity'=>10,'options'=>is_array($template)?($template['options'] ?? []):[]];
            $inventory=DB::table('nro_inventory_items')->insertGetId(['account_id'=>$account->id,'fingerprint'=>hash('sha256',Str::uuid()),'template_id'=>$id,
                'item_json'=>json_encode($item),'locations_json'=>'[]','quantity'=>10,'reserved'=>0,
                ...NroItemFilters::inventoryColumns($item),...NroItemFilters::extraInventoryColumns($item),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('item_listing_items')->insert(['listing_id'=>$listing,'inventory_item_id'=>$inventory,'quantity'=>1]);
        }
        return $listing;
    }
    private function order(NroAccount $account, int $listing, string $status): int
    {
        return DB::table('item_orders')->insertGetId(['buyer_id'=>$this->admin->id,'seller_id'=>$account->user_id,'account_id'=>$account->id,
            'listing_id'=>$listing,'request_key'=>(string)Str::uuid(),'recipient_name'=>'buyer','server_index'=>0,'server_id'=>10,
            'price'=>100,'title'=>'Test order','status'=>$status,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function test_admin_filters_cover_group_item_server_bundle_price_and_sort(): void
    {
        $a=$this->account(); $single=$this->listing($a,[223],100); $combo=$this->listing($a,[223,14],300);
        $this->listing($a,[14],200);
        $this->getJson('/admin/nro-shop/listings?group=upgrade_stones&itemId=223&server=10&bundle=single&minPrice=50&maxPrice=150')
            ->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$single)->assertJsonPath('filters.groups.0.filterMode','equipment');
        $this->getJson('/admin/nro-shop/listings?bundle=combo')->assertOk()->assertJsonPath('data.0.id',$combo)->assertJsonPath('total',1);
        $this->getJson('/admin/nro-shop/listings?sort=price_asc')->assertJsonPath('data.0.id',$single);
        $this->getJson('/admin/nro-shop/listings?sort=price_desc')->assertJsonPath('data.0.id',$combo);
        $this->getJson('/admin/nro-shop/listings?server=999')->assertJsonPath('total',0);
        $this->getJson('/admin/nro-shop/listings?minPrice=200&maxPrice=100')->assertUnprocessable();
        $this->getJson('/admin/nro-shop/listings?maxPrice=0')->assertJsonPath('total',0);
    }
    public function test_combo_equipment_conditions_must_match_the_same_item(): void
    {
        $a=$this->account();
        $this->listing($a,[['id'=>0,'options'=>[['optionId'=>102,'param'=>5]]],['id'=>21,'options'=>[['optionId'=>50,'param'=>20]]]]);
        $this->getJson('/admin/nro-shop/listings?group=equipment&equipmentType=0&minStars=5&stat=damage')->assertOk()->assertJsonPath('total',0);
        $match=$this->listing($a,[['id'=>0,'options'=>[['optionId'=>102,'param'=>5],['optionId'=>50,'param'=>20]]]]);
        $this->getJson('/admin/nro-shop/listings?group=equipment&equipmentType=0&minStars=5&stat=damage')->assertJsonPath('total',1)->assertJsonPath('data.0.id',$match);
    }
    public function test_admin_can_filter_hidden_custom_groups_and_stale_links_reset(): void
    {
        $a=$this->account(); $id=$this->listing($a,[223]);
        $config=$this->getJson('/admin/nro-shop/item-groups')->assertOk()->json();
        $config['groups'][]=['key'=>'custom_stones','name'=>'Custom stones','visible'=>false,'position'=>0,'filterMode'=>'items','ids'=>[223]];
        $this->patchJson('/admin/nro-shop/item-groups',$config)->assertOk();
        $this->getJson('/admin/nro-shop/listings?group=custom_stones&itemId=223')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$id)
            ->assertJsonPath('filters.groups.0.label','Custom stones (ẩn ở shop)');
        $this->getJson('/api/nro-shop/listings?group=custom_stones')->assertOk()->assertJsonPath('clearedFilters',['group']);
        $this->getJson('/admin/nro-shop/listings?group=deleted_group&itemId=223&page=99')->assertOk()->assertJsonPath('page',1)->assertJsonPath('total',1);
    }
    public function test_filters_preserve_owner_scope_and_admin_search(): void
    {
        $seller=User::factory()->create(); $seller->assignRole('ctv'); $seller->givePermissionTo('item-listings.manage');
        $a=$this->account($seller); $own=$this->listing($a); $this->listing($this->account());
        $this->actingAs($seller,'web')->getJson('/admin/nro-shop/listings?group=upgrade_stones')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$own);
        $this->getJson('/admin/nro-shop/listings?group=upgrade_stones&q='.urlencode('#tk:'.$a->id))->assertJsonPath('total',1);
        $this->actingAs(User::factory()->create(),'web')->getJson('/admin/nro-shop/listings')->assertForbidden();
    }
    public function test_delete_cancels_queued_scans_archives_listings_and_preserves_history(): void
    {
        $a=$this->account(); $listing=$this->listing($a); $order=$this->order($a,$listing,'refunded');
        $job=DB::table('nro_worker_jobs')->insertGetId(['account_id'=>$a->id,'type'=>'snapshot','status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
        $this->getJson('/api/nro-shop/listings')->assertJsonPath('total',1); // Warm the public cache.
        $this->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertOk();
        $this->assertSoftDeleted('nro_accounts',['id'=>$a->id]);
        $this->assertDatabaseHas('item_listings',['id'=>$listing,'status'=>'archived']);
        $this->assertDatabaseHas('nro_worker_jobs',['id'=>$job,'status'=>'cancelled']);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'refunded']);
        $this->assertDatabaseCount('nro_inventory_items',1);
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/admin/nro-shop/status')->assertOk()->assertJsonPath('total',0)->assertJsonPath('orders',1)->assertJsonPath('jobs',1);
        $this->getJson('/admin/nro-shop/orders')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$order);
        $this->getJson('/admin/nro-shop/jobs')->assertOk()->assertJsonPath('total',1);
        $this->getJson('/admin/nro-shop/accounts/'.$a->id)->assertNotFound();
        DB::transaction(fn()=>app(NroAccountRegistration::class)->available($a->account_name,37));
    }
    public function test_deleting_a_nick_withdraws_unsold_listing_but_keeps_sold_history(): void
    {
        $game=\App\Models\GameType::create(['name'=>'NRO']);
        $category=\App\Models\Category::create(['name'=>'Nick','slug'=>'nick-test','game_type_id'=>$game->id,'template'=>'default','status'=>'active']);
        foreach (['not_sold','sold'] as $status) {
            $a=$this->account(null,'nick');
            $nick=new \App\Models\Nick;
            $nick->forceFill(['user_id'=>$this->admin->id,'category_id'=>$category->id,'game_account_id'=>$a->id,'account_name'=>$a->account_name,
                'account_password'=>'test-only','price'=>100,'description'=>'Test','status'=>$status,'listing_type'=>'normal'])->save();
            $this->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertOk();
            $this->assertDatabaseHas('nicks',['id'=>$nick->id,'game_account_id'=>$a->id,'status'=>$status==='not_sold'?'deleted':'sold']);
            $this->assertSoftDeleted('nro_accounts',['id'=>$a->id]);
        }
    }
    public function test_stale_active_receiving_session_blocks_deletion_even_for_terminal_order(): void
    {
        $a=$this->account(); $listing=$this->listing($a); $order=$this->order($a,$listing,'completed');
        DB::table('nro_delivery_sessions')->insert(['order_id'=>$order,'request_key'=>(string)Str::uuid(),'mode'=>'manual','status'=>'ready','created_at'=>now(),'updated_at'=>now()]);
        $this->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertUnprocessable();
        $this->assertNotSoftDeleted('nro_accounts',['id'=>$a->id]);
    }
    public function test_seller_picker_keeps_the_admin_role_boundary_without_extra_permissions(): void
    {
        $adminRole=Role::findByName('admin','web'); $adminRole->revokePermissionTo('nro-sale-policy.manage');
        $this->admin->revokePermissionTo('nro-sale-policy.manage');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson('/admin/nro-shop/sellers')->assertOk();
    }
    public function test_delete_blocks_unfinished_orders_without_changing_any_listing(): void
    {
        $a=$this->account(); $listing=$this->listing($a); $order=$this->order($a,$listing,'awaiting_receipt');
        $this->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertUnprocessable();
        $this->assertNotSoftDeleted('nro_accounts',['id'=>$a->id]);
        $this->assertDatabaseHas('item_listings',['id'=>$listing,'status'=>'active']);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'awaiting_receipt']);
    }
    public function test_delete_blocks_processing_review_and_live_leases(): void
    {
        foreach (['processing','review','failed'] as $status) {
            $a=$this->account(); DB::table('nro_worker_jobs')->insert(['account_id'=>$a->id,'type'=>'snapshot','status'=>$status,
                'lease_until'=>$status==='failed'?now()->addMinute():null,'created_at'=>now(),'updated_at'=>now()]);
            $this->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertUnprocessable();
            $this->assertNotSoftDeleted('nro_accounts',['id'=>$a->id]);
        }
    }
    public function test_delete_blocks_held_inventory_even_if_order_state_is_inconsistent(): void
    {
        $a=$this->account(); $this->listing($a);
        DB::table('nro_inventory_items')->where('account_id',$a->id)->update(['reserved'=>1]);
        $this->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertUnprocessable();
    }
    public function test_delete_permissions_and_ownership(): void
    {
        $a=$this->account(); $actor=User::factory()->create();
        $this->actingAs($actor,'web')->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertForbidden();
        $actor->assignRole('ctv'); $actor->givePermissionTo('nro-accounts.manage');
        $this->deleteJson('/admin/nro-shop/accounts/'.$a->id)->assertNotFound();
        $own=$this->account($actor,'nick');
        $this->deleteJson('/admin/nro-shop/accounts/'.$own->id)->assertOk();
    }
    public function test_seller_picker_is_paginated_and_does_not_return_credentials_or_policy_details(): void
    {
        $role=Role::findByName('ctv','web'); $role->givePermissionTo('item-listings.manage');
        for ($i=0;$i<14;$i++) { $seller=User::factory()->create(); $seller->assignRole('ctv'); $a=$this->account($seller); $this->listing($a); }
        $notSeller=User::factory()->create(); $this->account($notSeller);
        $this->getJson('/admin/nro-shop/sellers')->assertOk()->assertJsonPath('total',14)->assertJsonCount(12,'data')
            ->assertJsonMissingPath('data.0.game_password')->assertJsonMissingPath('data.0.allowIds')->assertJsonPath('data.0.roles.0','ctv');
        $this->getJson('/admin/nro-shop/sellers?page=2')->assertJsonCount(2,'data');
        $this->getJson('/admin/nro-shop/sellers?q=%23'.$seller->id)->assertJsonPath('total',1)->assertJsonPath('data.0.userId',$seller->id);
        $this->getJson('/admin/nro-shop/seller-policy?q=%23'.$seller->id)->assertOk()->assertJsonPath('allowIds',[]);
    }
    public function test_seller_picker_respects_permission_scope_and_displays_disabled_sellers(): void
    {
        $seller=User::factory()->create(); $seller->assignRole('ctv'); $seller->givePermissionTo(['item-listings.manage','nro-sale-policy.manage']); $this->account($seller);
        DB::table('nro_seller_policies')->insert(['user_id'=>$seller->id,'selling_enabled'=>false,'deny_ids'=>'[]','created_at'=>now(),'updated_at'=>now()]);
        $other=User::factory()->create(); $other->givePermissionTo('item-listings.manage'); $this->account($other);
        $this->actingAs($seller,'web')->getJson('/admin/nro-shop/sellers')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.sellingEnabled',false);
        $this->getJson('/admin/nro-shop/sellers?q=%23'.$other->id)->assertJsonPath('total',0);
        $this->actingAs($other,'web')->getJson('/admin/nro-shop/sellers')->assertForbidden();
    }
}
