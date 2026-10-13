<?php
namespace Tests\Feature;
use App\Events\TradingBotActivity;
use App\Models\{Bot,GoldTransaction,InventoryMovement,Server,Transaction,User};
use App\Services\{InventoryMovementService,TransactionService};
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Event};
use Illuminate\Support\Str;
use Tests\TestCase;
class GoldTradingV3Test extends TestCase {
    use RefreshDatabase;
    private string $worker='00000000-0000-4000-8000-000000000101';
    private string $other='00000000-0000-4000-8000-000000000102';
    private string $secret='test-only-gold-secret-at-least-32-characters';
    private Server $server;
    private int $loginId;
    protected function setUp(): void {
        parent::setUp();config()->set('trading_v3',require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled',true);config()->set('trading_v3.hmac_secret',$this->secret);
        config()->set('services.app_api_key','legacy-test');Event::fake();
        $this->server=Server::create(['name'=>'business-test','status'=>true]);
        $this->loginId=(int)DB::table('server_game_login')->max('id')+1;
        DB::table('server_game_login')->insert(['id'=>$this->loginId,'name'=>'test-login','ip'=>'test.example','port'=>'14445']);
    }
    private function signed(string $method,string $path,array $data=[]) {
        $body=$method==='GET'?'':json_encode($data);$time=(string)time();$nonce=bin2hex(random_bytes(16));$tool='nro-trading-v3';
        $headers=['X-Tool-Id'=>$tool,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,
            'X-Tool-Signature'=>hash_hmac('sha256',implode("\n",[$method,$path,$tool,$time,$nonce,hash('sha256',$body)]),$this->secret)];
        return $method==='GET'?$this->get($path,[...$headers,'Accept'=>'application/json']):$this->json($method,$path,$data,$headers);
    }
    private function bot(string $type='selling_main',int $bars=10,int $cash=100000000,bool $enabled=true): Bot {
        return Bot::create(['name'=>'test-gold','account_name'=>'test-bot-'.Str::uuid(),'account_password'=>'test-password',
            'type'=>$type,'server_id'=>$this->server->id,'server_game_id'=>$this->loginId,'gold_bar_qty'=>$bars,'gold_qty'=>$cash,
            'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'41','coordinates'=>'652-336','status'=>$enabled]);
    }
    private function lease(Bot $b,?string $worker=null): void {
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$worker??$this->worker,'action'=>'lease'])->assertOk();
    }
    private function order(Bot $b,string $type='order',int $bars=2,int $cash=100000000,?User $u=null): GoldTransaction {
        return GoldTransaction::create(['type'=>$type,'user_id'=>($u??User::factory()->create())->id,'server_id'=>$b->server_id,
            'character_name'=>'lezit1','gold_qty'=>$bars*37000000+$cash,'gold_bar_qty'=>$bars,'pure_gold_qty'=>$cash,
            'amount_vnd'=>100000,'price_at_transaction'=>1000,'status'=>'pending','updated_by'=>'web']);
    }
    private function claim(Bot $b,GoldTransaction $o): string {
        return $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim'])
            ->assertOk()->json('data.processing_token');
    }
    private function phase(Bot $b,GoldTransaction $o,string $token,string $phase='trading'): array {
        $d=['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'phase','processing_token'=>$token,'step_id'=>(string)Str::uuid(),
            'character_id'=>1234,'phase'=>$phase];
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$d)->assertOk();return $d;
    }
    private function receipt(Bot $b,GoldTransaction $o,string $token,int $bars,int $cash,bool $done): array {
        $b->refresh();$sign=$o->type==='import'?1:-1;
        return ['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'receipt','processing_token'=>$token,
            'character_id'=>1234,'step_id'=>(string)Str::uuid(),'bars_qty'=>$bars,'pure_gold_qty'=>$cash,'completed'=>$done,
            'bot_snapshot'=>['stock_version'=>$b->stock_version+1,'gold_bar_qty'=>$b->gold_bar_qty+$sign*$bars,'gold_qty'=>$b->gold_qty+$sign*$cash],
            'evidence'=>['kind'=>$o->type==='import'?'import':'sale','confirmed'=>true,'fresh_login'=>true]];
    }
    private function transfer(Bot $from,Bot $to,int $bars,int $cash=0): array {
        $id=(string)Str::uuid();$d=['worker_id'=>$this->worker,'action'=>'claim','from_bot_id'=>$from->id,'to_bot_id'=>$to->id,'bars_qty'=>$bars,'pure_gold_qty'=>$cash];
        $result=$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$d)->assertOk();return [$id,$d,$result->json('data')];
    }
    private function transferReceipt(Bot $from,Bot $to,array $d,array $meta): array {
        $from->refresh();$to->refresh();return [...$d,'action'=>'receipt','processing_token'=>$meta['processing_token'],'step_id'=>(string)Str::uuid(),
            'from_snapshot'=>['stock_version'=>$from->stock_version+1,'gold_bar_qty'=>$from->gold_bar_qty-$d['bars_qty'],'gold_qty'=>$from->gold_qty-$d['pure_gold_qty']],
            'to_snapshot'=>['stock_version'=>$to->stock_version+1,'gold_bar_qty'=>$to->gold_bar_qty+$d['bars_qty'],'gold_qty'=>$to->gold_qty+$d['pure_gold_qty']],
            'evidence'=>['kind'=>'transfer','confirmed'=>true,'fresh_login'=>true]];
    }
    public function test_full_bot_list_csv_roles_login_and_readonly_targets(): void {
        $b=$this->bot('selling_main, import_sub',10,100,true);$off=$this->bot('import_main',0,0,false);
        $this->signed('GET','/app/v3/gold/bots')->assertOk()->assertJsonCount(2,'data')->assertJsonPath('data.0.types',['selling_main','import_sub'])
            ->assertJsonPath('data.0.login.ip','test.example')->assertJsonPath('data.0.login.port',14445)->assertJsonPath('data.0.gold_bar_template_id',457)
            ->assertJsonPath('data.1.status',false);
        $this->getJson('/api/bots?server_id='.$this->server->id.'&type=selling_main')->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/bots/overview')->assertOk()->assertJsonCount(1,'data.selling_main');
        $this->lease($b);$before=$b->refresh()->getAttributes();
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,'gold_qty'=>10,
            'gold_bar_qty'=>1000,'status'=>false,'coordinates'=>'0-0','proxy'=>'other'])->assertUnprocessable();
        $this->assertSame($before,$b->refresh()->getAttributes());
    }
    public function test_mixed_sale_partial_progress_and_duplicate_receipts_are_component_exact(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,1,0,false);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.delivery_data.progress',['bars'=>1,'pure'=>0]);
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
        $p=$this->receipt($b,$o,$token,0,50000000,false);$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $p=$this->receipt($b,$o,$token,1,50000000,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.remaining',['bars'=>0,'pure'=>0])->assertJsonPath('data.status','completed');
        $this->assertSame(8,$b->refresh()->gold_bar_qty);$this->assertSame(0,$b->gold_qty);
        $this->signed('GET','/app/v3/gold-transactions?worker_id='.$this->worker)->assertJsonCount(0,'data');
        $p['bars_qty']=0;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
    }
    public function test_invalid_direction_completion_snapshot_and_overdelivery_roll_back(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,1,0,false);$count=InventoryMovement::count();
        $wrong=$p;$wrong['completed']=true;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['evidence']['kind']='import';$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['bot_snapshot']['gold_qty']--; $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertConflict();
        $wrong=$p;$wrong['bars_qty']=3;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements',$count);$this->assertSame(10,$b->refresh()->gold_bar_qty);$this->assertSame(['bars'=>0,'pure'=>0],$o->refresh()->delivery_data['progress']);
    }
    public function test_import_credits_only_full_delivery_once_and_preserves_wallet_quote(): void {
        $b=$this->bot('import_main',0,0);$this->lease($b);$u=User::factory()->create(['balance'=>1000]);$o=$this->order($b,'import',2,100000000,$u);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,1,50000000,false);$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $this->assertSame(1000,(int)$u->refresh()->balance);$this->assertFalse(Transaction::where('idempotency_key','gold-import-credit:'.$o->id)->exists());
        $p=$this->receipt($b,$o,$token,1,50000000,true);$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $this->assertSame(101000,(int)$u->refresh()->balance);$this->assertSame(1,Transaction::where('idempotency_key','gold-import-credit:'.$o->id)->count());
        $this->assertSame(2,$b->refresh()->gold_bar_qty);$this->assertSame(100000000,$b->gold_qty);
    }
    public function test_wallet_overflow_rolls_back_final_import_receipt(): void {
        $b=$this->bot('import_main',0,0);$this->lease($b);$u=User::factory()->create(['balance'=>TransactionService::MAX_BALANCE]);$o=$this->order($b,'import',1,0,$u);$t=$this->claim($b,$o);$this->phase($b,$o,$t);
        $count=InventoryMovement::count();$p=$this->receipt($b,$o,$t,1,0,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertUnprocessable();
        $this->assertSame(0,$b->refresh()->gold_bar_qty);$this->assertSame('processing',$o->refresh()->status);$this->assertDatabaseCount('inventory_movements',$count);
    }
    public function test_frozen_order_terms_character_and_phase_idempotency(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$t=$this->claim($b,$o);$p=$this->phase($b,$o,$t);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $p['phase']='waiting_stock';$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
        $p['step_id']=(string)Str::uuid();$p['character_id']=1235;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
        $o->update(['amount_vnd'=>1]);$p['character_id']=1234;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
        $o->update(['amount_vnd'=>100000,'gold_qty'=>1]);$new=$this->order($b);$new->update(['gold_qty'=>1]);
        $other=$this->bot();$this->lease($other);$this->signed('PUT',"/app/v3/gold-transactions/{$new->id}",['worker_id'=>$this->worker,'bot_id'=>$other->id,'action'=>'claim'])->assertUnprocessable();
    }
    public function test_done_ack_survives_bot_release_takeover_and_web_off(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$t=$this->claim($b,$o);$this->phase($b,$o,$t);
        $b->update(['status'=>false]);$p=$this->receipt($b,$o,$t,2,100000000,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertOk();
        $b->update(['status'=>true]);$this->lease($b,$this->other);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('ack_id',$p['step_id']);
        $this->assertSame($this->other,$b->refresh()->v3_worker_id);$this->assertSame(8,$b->gold_bar_qty);
    }
    public function test_owner_server_role_disabled_and_busy_claim_guards(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$this->claim($b,$o);
        $o2=$this->order($b);$this->signed('PUT',"/app/v3/gold-transactions/{$o2->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim'])->assertConflict();
        $this->signed('GET','/app/v3/gold-transactions?worker_id='.$this->other)->assertJsonCount(1,'data');
        $this->signed('GET',"/app/v3/gold-transactions/{$o->id}?worker_id={$this->other}")->assertForbidden();
        $b->forceFill(['v3_lease_until'=>now()->subMinute()])->save();$this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->other,'action'=>'lease'])->assertConflict();
        $aux=$this->bot('selling_sub');$this->lease($aux);$this->signed('PUT',"/app/v3/gold-transactions/{$o2->id}",['worker_id'=>$this->worker,'bot_id'=>$aux->id,'action'=>'claim'])->assertUnprocessable();
        $b2=$this->bot();$this->lease($b2);$server=Server::create(['name'=>'other-test','status'=>true]);$o2->update(['server_id'=>$server->id]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o2->id}",['worker_id'=>$this->worker,'bot_id'=>$b2->id,'action'=>'claim'])->assertUnprocessable();
        $b2->update(['status'=>false]);$this->signed('PUT',"/app/v3/gold/bots/{$b2->id}",['worker_id'=>$this->worker,'action'=>'lease'])->assertConflict();
        $b2->update(['status'=>true,'server_game_id'=>0]);$this->signed('PUT',"/app/v3/gold/bots/{$b2->id}",['worker_id'=>$this->worker,'action'=>'lease'])->assertConflict();
    }
    public function test_refill_waiting_order_atomic_balanced_and_no_wallet_credit(): void {
        $main=$this->bot('selling_main',0,0);$aux=$this->bot('selling_sub,import_sub',10,100000000);$this->lease($main);$this->lease($aux);
        $o=$this->order($main,'order',2,100000000);$t=$this->claim($main,$o);$this->assertSame('waiting_stock',$o->refresh()->delivery_phase);
        [$id,$d,$m]=$this->transfer($aux,$main,2,100000000);
        $this->signed('GET','/app/v3/gold-transfers?worker_id='.$this->worker)->assertOk()->assertJsonPath('data.0.id',$id);
        $this->signed('GET','/app/v3/gold-transfers?worker_id='.$this->other)->assertJsonCount(0,'data');
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$main->id,'action'=>'phase','processing_token'=>$t,'step_id'=>(string)Str::uuid(),'phase'=>'trading','character_id'=>1234])->assertConflict();
        $p=$this->transferReceipt($aux,$main,$d,$m);$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
        $this->assertSame(8,$aux->refresh()->gold_bar_qty);$this->assertSame(2,$main->refresh()->gold_bar_qty);$this->assertSame(100000000,$main->gold_qty);
        $this->assertSame(0,InventoryMovement::where('transaction_type','gold_transfer')->sum('quantity_delta'));$this->assertDatabaseCount('transactions',0);
        $this->signed('GET','/app/v3/gold-transfers?worker_id='.$this->worker)->assertJsonCount(0,'data');
        $this->phase($main,$o,$t);$p2=$this->receipt($main,$o,$t,2,100000000,true);$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p2)->assertOk();
        $p['bars_qty']=1;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertConflict();
    }
    public function test_storage_receive_capacity_source_empty_and_direction_guards(): void {
        $source=$this->bot('import_main',5,0);$aux=$this->bot('import_sub',997,0);$seller=$this->bot('selling_main',0,0);
        foreach([$source,$aux,$seller] as $b) $this->lease($b);
        $d=['worker_id'=>$this->worker,'action'=>'claim','from_bot_id'=>$source->id,'to_bot_id'=>$aux->id,'bars_qty'=>3];
        $this->signed('PUT','/app/v3/gold-transfers/'.Str::uuid(),$d)->assertConflict();
        $d['bars_qty']=2;[$id,$d,$m]=$this->transfer($source,$aux,2);
        $this->assertSame(0,GoldTradingService::receiveRoom($aux));$this->assertSame(3,GoldTradingService::available($source)['bars']);
        $p=$this->transferReceipt($source,$aux,$d,$m);$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();$this->assertSame(999,$aux->refresh()->gold_bar_qty);
        $bad=['worker_id'=>$this->worker,'action'=>'claim','from_bot_id'=>$seller->id,'to_bot_id'=>$source->id,'bars_qty'=>1];
        $this->signed('PUT','/app/v3/gold-transfers/'.Str::uuid(),$bad)->assertUnprocessable();
        $bad['from_bot_id']=$aux->id;$bad['to_bot_id']=$seller->id;$bad['bars_qty']=1000;$this->signed('PUT','/app/v3/gold-transfers/'.Str::uuid(),$bad)->assertUnprocessable();
        $aux->forceFill(['gold_bar_qty'=>0])->save();$bad['bars_qty']=1;$this->signed('PUT','/app/v3/gold-transfers/'.Str::uuid(),$bad)->assertConflict();
        $bad['from_bot_id']=$seller->id;$bad['to_bot_id']=$seller->id;$this->signed('PUT','/app/v3/gold-transfers/'.Str::uuid(),$bad)->assertUnprocessable();
    }
    public function test_transfer_reservation_survives_expired_lease_and_cancel_is_idempotent(): void {
        $source=$this->bot('import_main',5,0);$aux=$this->bot('selling_sub',0,0);$this->lease($source);$this->lease($aux);
        [$id,$d,$m]=$this->transfer($source,$aux,2);$source->forceFill(['v3_lease_until'=>now()->subMinute()])->save();
        $this->signed('PUT',"/app/v3/gold/bots/{$source->id}",['worker_id'=>$this->other,'action'=>'lease'])->assertConflict();
        $this->signed('PUT',"/app/v3/gold/bots/{$source->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>5])->assertConflict();
        $cancel=[...$d,'action'=>'cancel','processing_token'=>$m['processing_token'],'step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'unchanged'=>false]];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertUnprocessable();$cancel['evidence']['unchanged']=true;
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertOk()->assertJsonPath('data.status','cancelled');
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertOk();$this->assertSame(5,GoldTradingService::available($source->fresh())['bars']);
        $this->assertSame(0,$aux->refresh()->gold_bar_qty);
    }
    public function test_transfer_snapshot_mismatch_rolls_back_both_and_terminal_ack_after_takeover(): void {
        $from=$this->bot('import_main',10,0);$to=$this->bot('selling_sub',0,0);$this->lease($from);$this->lease($to);
        [$id,$d,$m]=$this->transfer($from,$to,3);$p=$this->transferReceipt($from,$to,$d,$m);$bad=$p;$bad['to_snapshot']['gold_bar_qty']=2;
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$bad)->assertConflict();$this->assertSame(10,$from->refresh()->gold_bar_qty);$this->assertSame(0,$to->refresh()->gold_bar_qty);
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();
        foreach([$from,$to] as $b) {$this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertOk();$this->lease($b,$this->other);}
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();$this->assertSame($this->other,$from->refresh()->v3_worker_id);
        $retry=$d;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$retry)->assertOk()->assertJsonPath('data.status','completed');
        $retry['bars_qty']=2;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$retry)->assertConflict();
    }
    public function test_v2_is_excluded_until_release_and_cannot_take_v3_orders(): void {
        $b=$this->bot('selling_main,import_sub');$this->lease($b);$o=$this->order($b);$headers=['X-APP-KEY'=>'legacy-test'];
        $this->getJson('/app/v2/bots?type=selling_main',$headers)->assertOk()->assertJsonCount(0,'data');
        $this->putJson("/app/v2/bots/{$b->id}",['gold_qty'=>0],$headers)->assertConflict();
        $this->getJson('/app/v1/bots?type=selling_main',$headers)->assertOk()->assertJsonCount(0,'data');
        $this->putJson("/app/v1/bots/{$b->id}",['gold_qty'=>0],$headers)->assertConflict();
        $this->putJson("/app/v2/gold-transactions/{$o->id}",['status'=>'processing','bot_id'=>$b->id,'gold_bar_qty'=>10,'gold_qty'=>100000000],$headers)->assertConflict();
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertOk();
        $this->getJson('/app/v2/bots?type=selling_main',$headers)->assertOk()->assertJsonCount(1,'data');
        $this->putJson("/app/v2/gold-transactions/{$o->id}",['status'=>'processing','bot_id'=>$b->id,'gold_bar_qty'=>10,'gold_qty'=>100000000],$headers)->assertOk();
        $this->assertSame($b->id,(int)$o->refresh()->bot_id);$this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'lease'])->assertConflict();
    }
    public function test_waiting_stock_can_refresh_baseline_but_trading_cannot(): void {
        $b=$this->bot('selling_main',0,0);$this->lease($b);$o=$this->order($b,'order',2,0);$token=$this->claim($b,$o);
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,'gold_bar_qty'=>2,'gold_qty'=>0])->assertOk();
        $this->assertSame(['bars'=>0,'pure'=>0],$o->refresh()->delivery_data['progress']);$this->phase($b,$o,$token);
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>2,'gold_bar_qty'=>2,'gold_qty'=>0])->assertConflict();
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertConflict();
        $this->assertSame(1,$b->refresh()->stock_version);
    }
    public function test_activity_is_transient_and_admin_cannot_complete_or_cancel_owned_orders(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$this->claim($b,$o);$before=$b->refresh()->getAttributes();
        $this->signed('POST',"/app/v3/gold/bots/{$b->id}/activity",['worker_id'=>$this->worker,'code'=>'refilling','map_id'=>1,'zone'=>2])->assertOk();
        $this->assertSame($before,$b->refresh()->getAttributes());Event::assertDispatched(TradingBotActivity::class,fn($e)=>$e->activity['bot_type']==='bot' && $e->activity['code']==='refilling');
        \Spatie\Permission\Models\Role::findOrCreate('super-admin','web');$admin=User::factory()->create();$admin->assignRole('super-admin');$this->actingAs($admin);
        $this->put('/admin/orders/'.$o->id.'/status',['status'=>'completed'])->assertSessionHasErrors('status');
        $resource=(new \App\Http\Resources\GoldTransaction\GoldTransactionResource($o->refresh()))->resolve();
        $this->assertFalse($resource['can_complete']);$this->assertFalse($resource['can_cancel']);$this->assertFalse($resource['can_refund']);
        $importer=$this->bot('import_main');$this->lease($importer);$i=$this->order($importer,'import');$this->claim($importer,$i);
        $this->put('/admin/imports/'.$i->id.'/status',['status'=>'completed'])->assertSessionHasErrors('status');$this->assertSame('processing',$i->refresh()->status);
    }
}
