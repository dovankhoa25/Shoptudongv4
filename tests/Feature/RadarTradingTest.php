<?php
namespace Tests\Feature;

use App\Models\{Bot,GoldPrice,GoldTradingPolicy,GoldTransaction,InventoryMovement,Server,User};
use App\Services\{GoldTradingPolicyService,RadarTradingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RadarTradingTest extends TestCase {
    use RefreshDatabase;
    private Server $server;
    private string $secret='radar-isolated-hmac-test-only-at-least-32-characters';
    protected function setUp(): void {
        parent::setUp();config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),
            'trading_v3.enabled'=>true,'trading_v3.hmac_secret'=>$this->secret]);
        DB::table('server_game_login')->updateOrInsert(['id'=>16],['name'=>'supper 2','ip'=>'dragonsuper.teamobi.com','port'=>'17001']);
        $this->server=Server::create(['name'=>'radar-test','status'=>true,'ip'=>'dragonsuper.teamobi.com','port'=>'17001']);
        $this->server->forceFill(['gold_radar_enabled'=>true])->save();
        GoldPrice::create(['server_id'=>$this->server->id,'price'=>3750,'import_price'=>3000,'status'=>true]);
        GoldTradingPolicy::create(['server_id'=>$this->server->id,'asset_type'=>'radar','sell_enabled'=>true,'import_enabled'=>true]);
    }
    private function bot(string $type='selling_main,import_main',array $extra=[]): Bot {
        return Bot::create(['name'=>'radar-bot','account_name'=>'private-test-'.Str::uuid(),'account_password'=>'private-test',
            'server_id'=>$this->server->id,'server_game_id'=>16,'type'=>$type,'status'=>true,
            'gold_qty'=>0,'gold_bar_qty'=>0,'nrj_gold_bar_qty'=>0,'radar_qty'=>3,'bag_capacity'=>20,'bag_free_slots'=>2,
            'map_id'=>'84','area_number'=>'41','coordinates'=>'652-336',...$extra]);
    }
    private function signed(string $path,array $d) {
        $body=json_encode($d);$time=(string)time();$nonce=bin2hex(random_bytes(16));$tool='nro-trading-v3';
        return $this->json('PUT',$path,$d,['X-Tool-Id'=>$tool,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,
            'X-Tool-Signature'=>hash_hmac('sha256',implode("\n",['PUT',$path,$tool,$time,$nonce,hash('sha256',$body)]),$this->secret)]);
    }
    private function order(Bot $bot,string $type='order',int $qty=3): GoldTransaction {
        return GoldTransaction::create(['type'=>$type,'user_id'=>User::factory()->create(['balance'=>100])->id,
            'server_id'=>$bot->server_id,'character_name'=>'radarCustomer','status'=>'pending','gold_bar_template_id'=>281,
            'gold_bar_qty'=>$qty,'pure_gold_qty'=>0,'gold_qty'=>$qty*37500000,'price_at_transaction'=>3000,'amount_vnd'=>12500]);
    }
    private function receipt(Bot $bot,GoldTransaction $order,int $progress): array {
        $import=$order->type==='import';$delta=$import?1:-1;
        return ['action'=>'receipt','bot_id'=>$bot->id,'gold_bar_template_id'=>281,'character_id'=>1234,'step_id'=>(string)Str::uuid(),
            'bars_qty'=>1,'pure_gold_qty'=>0,'completed'=>$progress===3,'evidence'=>['kind'=>$import?'import':'sale','confirmed'=>true],
            'bot_snapshot'=>['stock_version'=>$progress,'gold_qty'=>0,'gold_bar_qty'=>0,'nrj_gold_bar_qty'=>0,
                'radar_qty'=>3+$delta*$progress,'bag_capacity'=>20,'bag_free_slots'=>2-$delta*$progress]];
    }
    public function test_public_stock_and_room_sum_main_and_aux_once_without_pending_orders(): void {
        $main=$this->bot();$this->bot('selling_sub,import_sub',['radar_qty'=>5,'bag_free_slots'=>4]);
        $this->bot('selling_sub,import_sub',['radar_qty'=>40,'bag_capacity'=>54,'bag_free_slots'=>10,'status'=>false]);
        $this->bot('import_sub',['radar_qty'=>0,'bag_capacity'=>null,'bag_free_slots'=>null]);
        $this->order($main,'order',999);$this->order($main,'import',999);
        $summary=app(RadarTradingService::class)->summary($this->server);
        $this->assertSame(8,$summary['sell_qty']);$this->assertSame(6,$summary['import_room']);
        $this->assertSame(48,$summary['stock_qty']);$this->assertSame(1,$summary['unknown_capacity_bots']);
        $this->getJson('/api/bots/overview')->assertOk()->assertJsonPath('data.radar_servers.0.sell_qty',8)
            ->assertJsonPath('data.import_main.0.radar_receive_room',2)->assertDontSee('private-test');
        $this->getJson('/api/server-prices')->assertJsonCount(1,'data.0.gold_trading_options')
            ->assertJsonPath('data.0.gold_trading_options.0.asset_type','radar');
    }
    public function test_import_room_uses_all_occupied_slots_and_admin_limit_counts_disabled_inventory(): void {
        $this->bot('import_main',['radar_qty'=>3,'bag_capacity'=>54,'bag_free_slots'=>0]);
        $this->bot('import_sub',['radar_qty'=>5,'bag_free_slots'=>10]);
        $this->bot('selling_sub',['radar_qty'=>7,'status'=>false]);
        GoldTradingPolicy::where('asset_type','radar')->update(['import_stock_limit_bars'=>18]);
        $this->assertSame(3,app(RadarTradingService::class)->summary($this->server->fresh())['import_room']);
        app(GoldTradingPolicyService::class)->assertImportAllowed($this->server->id,281,3,0);
        $user=User::factory()->create();Passport::actingAs($user,['profile:read','profile:write']);
        $this->postJson('/api/imports',['server_id'=>$this->server->id,'character_name'=>'radarCustomer',
            'gold_bar_template_id'=>281,'gold_bar_qty'=>4,'pure_gold_qty'=>0])->assertUnprocessable();
        $this->postJson('/api/imports',['server_id'=>$this->server->id,'character_name'=>'radarCustomer',
            'gold_bar_template_id'=>281,'gold_bar_qty'=>3,'pure_gold_qty'=>0])->assertCreated();
        $this->bot('checker',['radar_qty'=>4,'bag_free_slots'=>20]);
        $this->assertSame(0,app(RadarTradingService::class)->summary($this->server->fresh())['import_room']);
        $this->getJson('/api/bots/overview')->assertJsonPath('data.radar_servers.0.import_room',0);
    }
    public function test_sale_quote_is_radar_units_and_admission_does_not_reserve_pending_sales(): void {
        $main=$this->bot();$this->bot('selling_sub',['radar_qty'=>2]);$this->order($main,'order',999);
        $user=User::factory()->create(['balance'=>100000]);Passport::actingAs($user,['profile:read','profile:write']);
        $this->postJson('/api/orders',['server_id'=>$this->server->id,'character_name'=>'buyer','money_amount'=>60000,
            'gold_type'=>'bar','gold_bar_template_id'=>281])->assertUnprocessable();
        $this->postJson('/api/orders',['server_id'=>$this->server->id,'character_name'=>'buyer','money_amount'=>50000,
            'gold_type'=>'bar','gold_bar_template_id'=>281])->assertCreated()->assertJsonPath('data.gold_bars',5);
        $this->assertSame(50000,(int)$user->refresh()->balance);
    }
    public function test_partial_radar_sale_receipts_are_idempotent_and_preserve_other_assets(): void {
        $bot=$this->bot();$order=$this->order($bot);$path='/app/v3/gold-transactions/'.$order->id;
        $this->signed($path,['action'=>'start','bot_id'=>$bot->id,'gold_bar_template_id'=>281,'character_id'=>1234])->assertOk();
        foreach(range(1,3) as $progress){$d=$this->receipt($bot,$order,$progress);
            $this->signed($path,$d)->assertOk()->assertJsonPath('data.status',$progress===3?'completed':'processing');
            $this->signed($path,$d)->assertOk();}
        $this->assertSame(0,$bot->refresh()->radar_qty);$this->assertSame(5,$bot->bag_free_slots);
        $this->assertSame(-112500000,(int)InventoryMovement::where('movement_type','sale')->sum('quantity_delta'));
        $this->assertSame(3,InventoryMovement::where('movement_type','sale')->count());
    }
    public function test_partial_import_credits_once_only_after_complete_and_rejects_missing_capacity(): void {
        $bot=$this->bot('import_main',['bag_free_slots'=>5]);$order=$this->order($bot,'import');$path='/app/v3/gold-transactions/'.$order->id;
        $this->signed($path,['action'=>'start','bot_id'=>$bot->id,'gold_bar_template_id'=>281,'character_id'=>1234])->assertOk();
        $bad=$this->receipt($bot,$order,1);unset($bad['bot_snapshot']['bag_capacity']);$this->signed($path,$bad)->assertUnprocessable();
        foreach(range(1,3) as $progress){$d=$this->receipt($bot,$order,$progress);$d['bot_snapshot']['bag_free_slots']=5-$progress;
            $this->signed($path,$d)->assertOk();$this->signed($path,$d)->assertOk();
            $this->assertSame($progress===3?12600:100,(int)User::findOrFail($order->user_id)->balance);}
        $this->assertSame(6,$bot->refresh()->radar_qty);$this->assertSame(1,DB::table('transactions')->count());
    }
    public function test_radar_storage_and_refill_use_physical_room_and_the_same_receipt_ledger(): void {
        $main=$this->bot('import_main');$aux=$this->bot('selling_sub,import_sub',['radar_qty'=>0,'bag_free_slots'=>1]);
        $id=(string)Str::uuid();$path='/app/v3/gold-transfers/'.$id;
        $d=['action'=>'claim','from_bot_id'=>$main->id,'to_bot_id'=>$aux->id,'gold_bar_template_id'=>281,'bars_qty'=>2,'pure_gold_qty'=>0];
        $this->signed($path,$d)->assertConflict();$d['bars_qty']=1;$this->signed($path,$d)->assertOk();
        $d=[...$d,'action'=>'receipt','step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'kind'=>'transfer'],
            'from_snapshot'=>['stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>0,'nrj_gold_bar_qty'=>0,'radar_qty'=>2,'bag_capacity'=>20,'bag_free_slots'=>3],
            'to_snapshot'=>['stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>0,'nrj_gold_bar_qty'=>0,'radar_qty'=>1,'bag_capacity'=>20,'bag_free_slots'=>0]];
        $this->signed($path,$d)->assertOk();$this->signed($path,$d)->assertOk();
        $this->assertSame(2,$main->refresh()->radar_qty);$this->assertSame(0,$aux->refresh()->bag_free_slots);
        $main->update(['type'=>'selling_main']);$reverse=(string)Str::uuid();
        $this->signed('/app/v3/gold-transfers/'.$reverse,['action'=>'claim','from_bot_id'=>$aux->id,'to_bot_id'=>$main->id,
            'gold_bar_template_id'=>281,'bars_qty'=>1,'pure_gold_qty'=>0])->assertOk();
    }
    public function test_radar_is_opt_in_and_only_supported_on_the_three_exact_super_endpoints(): void {
        foreach([15=>['dragon11.teamobi.com',14446],16=>['dragonsuper.teamobi.com',17001],17=>['dragonsuper3.teamobi.com',17001]] as $login=>[$host,$port]) {
            DB::table('server_game_login')->updateOrInsert(['id'=>$login],['name'=>'test','ip'=>$host,'port'=>$port]);
            $this->assertTrue(RadarTradingService::supportedBot($this->bot(extra:['server_game_id'=>$login])));
        }
        $this->assertFalse(RadarTradingService::endpoint('dragon15.teamobi.com',14445));
        $this->server->forceFill(['gold_radar_enabled'=>false])->save();
        $this->assertCount(3,app(GoldTradingPolicyService::class)->publicOptions($this->server->fresh()));
        $user=User::factory()->create(['balance'=>100000]);Passport::actingAs($user,['profile:read','profile:write']);
        $this->postJson('/api/orders',['server_id'=>$this->server->id,'character_name'=>'buyer','money_amount'=>10000,
            'gold_type'=>'bar','gold_bar_template_id'=>281])->assertUnprocessable();
    }
    public function test_local_radar_transfer_receipt_needs_no_web_claim_and_retries_exactly_once(): void {
        $main=$this->bot('import_main');$aux=$this->bot('import_sub',['radar_qty'=>0,'bag_free_slots'=>5]);
        $id=(string)Str::uuid();$step=(string)Str::uuid();$path='/app/v3/gold-transfers/'.$id;
        $d=['action'=>'receipt','server_id'=>$this->server->id,'from_bot_id'=>$main->id,'to_bot_id'=>$aux->id,
            'gold_bar_template_id'=>281,'bars_qty'=>3,'pure_gold_qty'=>0,'step_id'=>$step,
            'evidence'=>['kind'=>'transfer','confirmed'=>true,'intent'=>['id'=>$step,'transfer_id'=>$id,
                'server_id'=>$this->server->id,'from_roles'=>'import_main','to_roles'=>'import_sub',
                'from_character_id'=>100,'to_character_id'=>200]],
            'from_snapshot'=>['stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>0,'nrj_gold_bar_qty'=>0,'radar_qty'=>0,'bag_capacity'=>20,'bag_free_slots'=>5],
            'to_snapshot'=>['stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>0,'nrj_gold_bar_qty'=>0,'radar_qty'=>3,'bag_capacity'=>20,'bag_free_slots'=>2]];
        // Configuration changes after the game confirmation cannot erase a real effect.
        $main->update(['status'=>false]);$this->server->forceFill(['gold_radar_enabled'=>false])->save();
        $this->signed($path,$d)->assertOk()->assertJsonPath('data.status','completed');
        $this->signed($path,$d)->assertOk();
        $this->assertSame(0,$main->refresh()->radar_qty);$this->assertSame(3,$aux->refresh()->radar_qty);
        $this->assertCount(0,\App\Services\TradingV3\GoldTradingService::activeTransfers());
        $this->assertSame(2,InventoryMovement::whereIn('movement_type',['transfer_out','transfer_in'])->count());
        $d['bars_qty']=2;$this->signed($path,$d)->assertConflict();
        $this->assertSame(2,InventoryMovement::whereIn('movement_type',['transfer_out','transfer_in'])->count());
    }
    public function test_radar_stock_cannot_mix_super_endpoints_in_one_business_server(): void {
        $this->bot();DB::table('server_game_login')->updateOrInsert(['id'=>15],['name'=>'Super 1','ip'=>'dragon11.teamobi.com','port'=>'14446']);
        $this->bot('selling_sub,import_sub',['server_game_id'=>15,'radar_qty'=>10,'bag_free_slots'=>10]);
        $this->assertFalse(RadarTradingService::supportedServer($this->server));
        $s=app(RadarTradingService::class)->summary($this->server);
        $this->assertSame(3,$s['sell_qty']);$this->assertSame(2,$s['import_room']);
    }
    public function test_admin_controls_radar_mode_direction_and_limit_and_refreshes_public_cache(): void {
        $this->bot();$this->getJson('/api/bots/overview')->assertJsonPath('data.radar_servers.0.sell_qty',3);
        $admin=User::factory()->create();\Spatie\Permission\Models\Role::findOrCreate('super-admin','web');$admin->assignRole('super-admin');
        $policies=collect(['pure','standard','nrj','radar'])->map(fn($asset)=>['asset_type'=>$asset,
            'sell_enabled'=>false,'import_enabled'=>$asset==='radar','import_stock_limit_bars'=>$asset==='radar'?4:null])->all();
        $path='/admin/gold-prices/servers/'.$this->server->id.'/trading-policy';
        $this->actingAs($admin)->from('/admin/gold-prices')->put($path,['gold_radar_enabled'=>true,'policies'=>$policies])
            ->assertRedirect('/admin/gold-prices')->assertSessionHasNoErrors();
        $this->getJson('/api/server-prices')->assertJsonPath('data.0.gold_trading_options.0.asset_type','radar')
            ->assertJsonPath('data.0.gold_trading_options.0.sell_enabled',false)->assertJsonPath('data.0.gold_trading_options.0.import_enabled',true);
        $this->getJson('/api/bots/overview')->assertJsonPath('data.radar_servers.0.import_room',1);
        $other=Server::create(['name'=>'ordinary server','ip'=>'other.example','port'=>'14445','status'=>true]);
        $this->put('/admin/gold-prices/servers/'.$other->id.'/trading-policy',['gold_radar_enabled'=>true,'policies'=>$policies])
            ->assertSessionHasErrors('gold_radar_enabled');
        $this->assertFalse($other->refresh()->gold_radar_enabled);
        $this->assertDatabaseMissing('gold_trading_policies',['server_id'=>$other->id,'asset_type'=>'radar']);
    }
}
