<?php

namespace Tests\Feature;

use App\Events\NroShopUpdated;
use App\Models\NroAccount;
use App\Models\User;
use App\Services\NroItemFilters;
use App\Services\NroItemGroupSettings;
use App\Services\NroSnapshotService;
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NroPotentialPowerFilterTest extends TestCase
{
    use RefreshDatabase;

    private NroAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Event::fake([NroShopUpdated::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo('item-listings.manage');
        $this->actingAs($admin, 'web');
        DB::table('servers')->insertOrIgnore(['id'=>10, 'name'=>'test', 'name_view'=>'Server test', 'status'=>true]);
        DB::table('server_game_login')->insertOrIgnore(['id'=>37, 'name'=>'test', 'ip'=>'127.0.0.1', 'port'=>'14445']);
        $this->account = NroAccount::create(['user_id'=>$admin->id, 'account_name'=>'test-'.Str::random(10),
            'game_password'=>'test-only', 'server'=>'login37', 'server_index'=>0, 'server_id'=>10,
            'server_game_id'=>37, 'usage_type'=>'warehouse', 'status'=>'active']);
    }

    private function listing(array $optionsByItem): int
    {
        $id = DB::table('item_listings')->insertGetId(['user_id'=>$this->account->user_id, 'account_id'=>$this->account->id,
            'title'=>'Test gear', 'price'=>100, 'status'=>'active', 'stock_mode'=>'fixed', 'packages_remaining'=>1,
            'created_at'=>now(), 'updated_at'=>now()]);
        foreach ($optionsByItem as $options) {
            $item = ['templateId'=>0, 'name'=>'Áo vải 3 lỗ', 'type'=>0, 'quantity'=>10, 'options'=>$options];
            $inventory = DB::table('nro_inventory_items')->insertGetId(['account_id'=>$this->account->id,
                'fingerprint'=>hash('sha256', Str::uuid()), 'template_id'=>0, 'item_json'=>json_encode($item),
                'locations_json'=>'[]', 'quantity'=>10, 'reserved'=>0, ...NroItemFilters::inventoryColumns($item),
                ...NroItemFilters::extraInventoryColumns($item), 'created_at'=>now(), 'updated_at'=>now()]);
            DB::table('item_listing_items')->insert(['listing_id'=>$id, 'inventory_item_id'=>$inventory, 'quantity'=>1]);
        }
        return $id;
    }

    public function test_bonus_ids_are_distinct_from_strength_requirement_and_other_stats(): void
    {
        foreach ([[101,25,true], [101,0,false], [101,-1,false], [83,0,true], [230,3,true], [230,0,false], [21,50,false], [168,1,false]] as [$id,$param,$expected]) {
            $flags = NroItemFilters::extraInventoryColumns(['options'=>[['optionId'=>$id, 'param'=>$param]]]);
            $this->assertSame($expected, $flags['filter_potential_power'], "option $id/$param");
        }
        $this->assertFalse(NroItemFilters::extraInventoryColumns(['options'=>[]])['filter_potential_power']);
    }

    public function test_public_and_admin_filters_keep_star_and_bonus_on_the_same_item(): void
    {
        // Both options exist in this combo, but on different pieces: it must not match.
        $this->listing([[['optionId'=>107,'param'=>5]], [['optionId'=>101,'param'=>25],['optionId'=>107,'param'=>3]]]);
        $match = $this->listing([[['optionId'=>101,'param'=>25],['optionId'=>107,'param'=>5],['optionId'=>50,'param'=>10]]]);
        $this->listing([[['optionId'=>21,'param'=>50],['optionId'=>107,'param'=>5]]]);
        foreach (['/api/nro-shop/listings', '/admin/nro-shop/listings'] as $url) {
            $this->getJson($url.'?group=equipment&equipmentType=0&minStars=5&stat=potential_power')
                ->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$match)
                ->assertJsonFragment(['value'=>'potential_power', 'label'=>'Tiềm năng, sức mạnh']);
            $this->getJson($url.'?group=equipment&minStars=5&stat=damage')
                ->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$match);
            $this->getJson($url.'?group=equipment&minStars=5')->assertOk()->assertJsonPath('total',3);
            $this->getJson($url.'?group=crystals&stat=potential_power')->assertOk()->assertJsonPath('clearedFilters',['stat']);
        }
    }

    public function test_migration_backfills_old_items_and_can_resume_without_changing_stock(): void
    {
        $this->listing([[['optionId'=>101,'param'=>25]], [['optionId'=>83,'param'=>0]],
            [['optionId'=>230,'param'=>3]], [['optionId'=>21,'param'=>50]], [['optionId'=>101,'param'=>0]]]);
        DB::table('nro_inventory_items')->update(['reserved'=>1]);
        $before = DB::table('nro_inventory_items')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        $migration = require database_path('migrations/2026_10_07_000003_add_nro_potential_power_filter.php');
        $migration->down();
        $migration->up();
        $migration->up();
        $after = DB::table('nro_inventory_items')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        $this->assertEquals($before, $after);
    }

    public function test_new_snapshot_indexes_the_bonus_without_changing_existing_star_or_hp_filters(): void
    {
        app(NroSnapshotService::class)->ingest($this->account, ['schemaVersion'=>1, 'catalogVersion'=>'17',
            'completeness'=>['bag'=>true, 'chest'=>true, 'equipped'=>true], 'snapshot'=>[
                'capturedAt'=>now()->toIso8601String(), 'character'=>['id'=>10,'name'=>'test','gender'=>0,'power'=>100000],
                'equipped'=>[], 'chest'=>[], 'collectionChest'=>[], 'bag'=>[
                    ['slot'=>0,'templateId'=>0,'quantity'=>1,'options'=>[
                        ['optionId'=>101,'param'=>25], ['optionId'=>107,'param'=>5], ['optionId'=>77,'param'=>20],
                    ]],
                ],
            ]]);
        $this->assertDatabaseHas('nro_inventory_items', ['account_id'=>$this->account->id,
            'filter_potential_power'=>true, 'filter_stars'=>5, 'filter_hp'=>true, 'filter_damage'=>false]);
    }

    public function test_metadata_bypasses_the_old_cached_stat_list(): void
    {
        $definitions = NroItemGroupSettings::definitions();
        $oldVersion = hash('sha256', json_encode([$definitions, []]).filemtime(resource_path('nro/item-templates.json')));
        ApiCache::remember('public:nro-metadata', 'filters:'.$oldVersion, 900, fn()=>['stats'=>[]]);
        $filters = new NroItemFilters($definitions, []);
        $this->assertNotSame($oldVersion, $filters->version());
        $stats = array_column($filters->metadata()['stats'], 'label', 'value');
        $this->assertSame('Tiềm năng, sức mạnh', $stats['potential_power']);
        $this->assertSame(['damage','life_steal','ki_steal','gold','hp','ki','other'], array_values(array_diff(array_keys($stats), ['potential_power'])));
    }
}
