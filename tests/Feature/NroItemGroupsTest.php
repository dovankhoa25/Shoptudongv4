<?php
namespace Tests\Feature;

use App\Events\NroShopUpdated;
use App\Models\NroAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\NroCatalog;
use App\Services\NroItemFilters;
use App\Services\NroItemGroupSettings;
use App\Services\NroSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NroItemGroupsTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp(); Cache::flush(); $this->withoutMiddleware(ThrottleRequests::class);
        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create(); $this->admin->assignRole('admin');
        $this->actingAs($this->admin, 'web');
        Event::fake([NroShopUpdated::class]);
    }
    private function config(): array { return $this->getJson('/admin/nro-shop/item-groups')->assertOk()->json(); }
    private function save(array $config): array { return $this->patchJson('/admin/nro-shop/item-groups', $config)->assertOk()->json(); }
    private function custom(string $mode = 'items', array $ids = [14,15]): array
    {
        return ['key'=>'event_rewards', 'name'=>'Vật phẩm sự kiện', 'visible'=>true, 'position'=>0, 'filterMode'=>$mode, 'ids'=>$ids];
    }
    private function listings(): void
    {
        $this->admin->givePermissionTo(['item-listings.manage','nro-accounts.manage']);
        DB::table('servers')->insertOrIgnore(['id'=>10,'name'=>'test','name_view'=>'Server test','status'=>true]);
        DB::table('server_game_login')->insertOrIgnore(['id'=>37,'name'=>'test','ip'=>'127.0.0.1','port'=>'14445']);
        $account = NroAccount::create(['user_id'=>$this->admin->id,'account_name'=>'test-warehouse','game_password'=>'local-test-only','server'=>'vt1',
            'server_index'=>0,'server_id'=>10,'server_game_id'=>37,'usage_type'=>'warehouse','status'=>'active']);
        app(NroSnapshotService::class)->ingest($account, ['schemaVersion'=>1,'catalogVersion'=>'17',
            'completeness'=>['bag'=>true,'chest'=>true,'equipped'=>true],
            'snapshot'=>['capturedAt'=>now()->toIso8601String(),'character'=>['id'=>10,'name'=>'testbot','gender'=>0,'power'=>100000],
                'equipped'=>[],'chest'=>[],'collectionChest'=>[],
                'bag'=>[['slot'=>0,'templateId'=>14,'quantity'=>2,'options'=>[]],
                    ['slot'=>1,'templateId'=>0,'quantity'=>1,'options'=>[['optionId'=>0,'param'=>100],['optionId'=>102,'param'=>5]]]]]]);
        foreach (DB::table('nro_inventory_items')->where('account_id',$account->id)->get() as $item) {
            $this->postJson('/admin/nro-shop/accounts/'.$account->id.'/listings', ['title'=>'Test '.$item->template_id,'price'=>200,
                'items'=>[['id'=>$item->id,'quantity'=>1]]])->assertOk();
        }
    }

    public function test_empty_settings_keeps_defaults_and_admin_reads_never_insert(): void
    {
        $config = $this->config();
        $this->assertCount(6, $config['groups']);
        $this->assertDatabaseMissing('settings', ['key'=>'nro_item_groups']);
        $this->getJson('/api/nro-shop/listings')->assertOk()
            ->assertJsonPath('filters.groups.0.filterMode','equipment')
            ->assertJsonPath('filters.groups.1.filterMode','items');
        $this->assertSame('support', app(NroItemFilters::class)->group(['id'=>2089,'type'=>27]));
        Event::assertNotDispatched(NroShopUpdated::class);
    }
    public function test_permissions_guard_reads_and_writes(): void
    {
        $config = $this->config(); $user = User::factory()->create();
        $this->actingAs($user)->getJson('/admin/nro-shop/item-groups')->assertForbidden();
        $this->patchJson('/admin/nro-shop/item-groups',$config)->assertForbidden();
        $user->givePermissionTo('nro-sale-policy.manage');
        $this->getJson('/admin/nro-shop/item-groups')->assertOk();
        $this->patchJson('/admin/nro-shop/item-groups',$config)->assertOk();
    }
    public function test_new_group_reclassifies_real_listings_and_invalidates_primed_caches(): void
    {
        $this->listings();
        $this->getJson('/api/nro-shop/listings?group=dragon_balls')->assertJsonPath('total',1);
        $this->getJson('/api/nro-shop/listings')->assertJsonCount(6,'filters.groups');
        $config = $this->config();
        foreach ($config['groups'] as &$group) $group['position'] += 1;
        unset($group);
        $config['groups'][] = $this->custom();
        $saved = $this->save($config);
        $this->assertSame('event_rewards',$saved['groups'][0]['key']);
        $this->assertSame('event_rewards',app(NroItemFilters::class)->group(NroCatalog::templates()[14]));
        $this->getJson('/api/nro-shop/listings?group=dragon_balls')->assertJsonPath('total',0);
        $this->getJson('/api/nro-shop/listings?group=event_rewards&itemId=14')->assertOk()->assertJsonPath('total',1)
            ->assertJsonPath('filters.groups.0.label','Vật phẩm sự kiện')->assertJsonPath('filters.groups.0.filterMode','items')
            ->assertJsonPath('filters.itemsByGroup.event_rewards.0.value','14');
        $this->assertNull(Setting::get('nro_sale_item_policy'));
        $this->getJson('/api/nro-shop/listings/'.DB::table('item_listings')->value('id'))->assertOk()->assertJsonMissingPath('clearedFilters');
        Event::assertDispatched(NroShopUpdated::class, fn($event) => $event->catalog && $event->adminResources === []);
    }
    public function test_hidden_group_preserves_assignments_and_delete_restores_defaults_and_old_urls(): void
    {
        $this->listings(); $config=$this->config(); $config['groups'][]=$this->custom();
        $config=$this->save($config);
        $inventory=DB::table('nro_inventory_items')->get()->toJson();
        $listings=DB::table('item_listings')->get()->toJson();
        foreach ($config['groups'] as &$g) if ($g['key']==='event_rewards') $g['visible']=false;
        unset($g); $config=$this->save($config);
        $this->assertSame('event_rewards',app(NroItemFilters::class)->group(NroCatalog::templates()[14]));
        $this->getJson('/api/nro-shop/listings?group=event_rewards&itemId=14&page=9')->assertOk()->assertJsonPath('total',2)
            ->assertJsonPath('currentPage',1)->assertJsonCount(6,'filters.groups')->assertJsonPath('clearedFilters',['group','itemId','page']);
        $config['groups']=array_values(array_filter($config['groups'],fn($g)=>$g['key']!=='event_rewards'));
        $this->save($config);
        $this->assertSame('dragon_balls',app(NroItemFilters::class)->group(NroCatalog::templates()[14]));
        $this->getJson('/api/nro-shop/listings?group=event_rewards')->assertOk()->assertJsonPath('total',2)->assertJsonPath('clearedFilters',['group']);
        $this->getJson('/api/nro-shop/listings?group=dragon_balls')->assertOk()->assertJsonPath('total',1);
        $this->assertSame($inventory,DB::table('nro_inventory_items')->get()->toJson());
        $this->assertSame($listings,DB::table('item_listings')->get()->toJson());
    }
    public function test_filter_mode_drives_equipment_queries_and_mode_changes_clear_stale_conditions(): void
    {
        $this->listings(); $config=$this->config(); $config['groups'][]=$this->custom('equipment',[0]);
        $config=$this->save($config);
        $this->getJson('/api/nro-shop/listings?group=event_rewards&equipmentType=0&stat=damage&minStars=5')
            ->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/nro-shop/listings?group=event_rewards&stat=hp')->assertOk()->assertJsonPath('total',0);
        foreach ($config['groups'] as &$g) if ($g['key']==='event_rewards') { $g['filterMode']='basic'; $g['name']='Tên mới'; }
        unset($g); $this->save($config);
        $this->getJson('/api/nro-shop/listings?group=event_rewards&stat=hp&page=9')->assertOk()->assertJsonPath('total',1)
            ->assertJsonPath('currentPage',1)->assertJsonPath('clearedFilters',['stat','page'])->assertJsonMissingPath('filters.itemsByGroup.event_rewards');
    }
    public function test_validation_is_atomic_and_stale_admin_cannot_overwrite_a_newer_save(): void
    {
        $initial=$this->config(); $valid=$initial; $valid['groups'][]=$this->custom();
        $saved=$this->save($valid);
        $this->patchJson('/admin/nro-shop/item-groups',$initial)->assertConflict();
        $bad=$saved; $bad['groups'][]=[...$this->custom(),'key'=>'another'];
        $this->patchJson('/admin/nro-shop/item-groups',$bad)->assertUnprocessable();
        $bad=$saved; $bad['groups'][]=[...$this->custom('items',[99999]),'key'=>'unknown'];
        $this->patchJson('/admin/nro-shop/item-groups',$bad)->assertUnprocessable();
        $bad=$saved; $bad['groups'][]=[...$this->custom('equipment',[20]),'key'=>'wrong_mode'];
        $this->patchJson('/admin/nro-shop/item-groups',$bad)->assertUnprocessable();
        $bad=$saved; $bad['groups']=array_values(array_filter($bad['groups'],fn($g)=>$g['key']!=='equipment'));
        $this->patchJson('/admin/nro-shop/item-groups',$bad)->assertUnprocessable();
        $this->assertSame($saved,$this->config());
        $this->patchJson('/admin/nro-shop/sale-policy',['enabled'=>false,'ids'=>[],'groupOverrides'=>[]])->assertUnprocessable();
        $this->assertSame($saved,$this->config());
    }
    public function test_existing_overrides_are_preserved_on_first_dynamic_save(): void
    {
        Setting::set('nro_item_group_overrides',json_encode([['id'=>14,'group'=>'support']]));
        $config=$this->config();
        $this->assertSame([14],collect($config['groups'])->firstWhere('key','support')['ids']);
        $this->save($config);
        $this->assertSame('support',app(NroItemFilters::class)->group(NroCatalog::templates()[14]));
    }
}
