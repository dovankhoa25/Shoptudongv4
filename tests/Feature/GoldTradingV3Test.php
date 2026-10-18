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
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim'])
            ->assertOk()->assertJsonPath('data.has_delivery_checkpoint',true);return (string)Str::uuid(); // Legacy wire field only.
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
        $from->refresh();$to->refresh();return [...$d,'action'=>'receipt','step_id'=>(string)Str::uuid(),
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
    public function test_completed_receipt_ack_survives_later_role_change_to_checker(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $receipt=$this->receipt($b,$o,$token,2,100000000,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$receipt)->assertOk()->assertJsonPath('data.status','completed');
        $count=InventoryMovement::count();
        $b->update(['type'=>'checker']);
        // A lost response must still acknowledge the frozen outbox after the completed bot is repurposed.
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$receipt)->assertOk()->assertJsonPath('ack_id',$receipt['step_id']);
        $this->assertDatabaseCount('inventory_movements',$count);
        $this->assertSame(8,$b->refresh()->gold_bar_qty);
    }
    public function test_invalid_direction_completion_snapshot_and_overdelivery_roll_back(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,1,0,false);$count=InventoryMovement::count();
        $wrong=$p;$wrong['completed']=true;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['evidence']['kind']='import';$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['bot_snapshot']['gold_qty']=-1; $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
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
        $this->assertSame(8,$b->refresh()->gold_bar_qty);
    }
    public function test_server_role_disabled_and_busy_claim_guards_without_worker_ownership(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$this->claim($b,$o);
        $o2=$this->order($b);$this->signed('PUT',"/app/v3/gold-transactions/{$o2->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim'])->assertConflict();
        $this->signed('GET','/app/v3/gold-transactions?worker_id='.$this->other)->assertJsonCount(2,'data');
        $this->signed('GET',"/app/v3/gold-transactions/{$o->id}?worker_id={$this->other}")->assertOk();
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->other,'action'=>'lease'])->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['worker_id'=>$this->other,'bot_id'=>$b->id,'action'=>'claim'])->assertOk()
            ->assertJsonPath('data.has_delivery_checkpoint',true);
        $aux=$this->bot('selling_sub');$this->lease($aux);$this->signed('PUT',"/app/v3/gold-transactions/{$o2->id}",['worker_id'=>$this->worker,'bot_id'=>$aux->id,'action'=>'claim'])->assertUnprocessable();
        $b2=$this->bot();$this->lease($b2);$server=Server::create(['name'=>'other-test','status'=>true]);$o2->update(['server_id'=>$server->id]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o2->id}",['worker_id'=>$this->worker,'bot_id'=>$b2->id,'action'=>'claim'])->assertUnprocessable();
        $disabledOrder=$this->order($b2);
        $b2->update(['status'=>false]);$this->signed('PUT',"/app/v3/gold/bots/{$b2->id}",['worker_id'=>$this->worker,'action'=>'lease'])->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$disabledOrder->id}",['worker_id'=>$this->worker,'bot_id'=>$b2->id,'action'=>'claim'])->assertConflict();
        $b2->update(['status'=>true,'server_game_id'=>0]);$this->signed('PUT',"/app/v3/gold/bots/{$b2->id}",['worker_id'=>$this->worker,'action'=>'lease'])->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$disabledOrder->id}",['worker_id'=>$this->worker,'bot_id'=>$b2->id,'action'=>'claim'])->assertConflict();
    }
    public function test_refill_waiting_order_atomic_balanced_and_no_wallet_credit(): void {
        $main=$this->bot('selling_main',0,0);$aux=$this->bot('selling_sub,import_sub',10,100000000);$this->lease($main);$this->lease($aux);
        $o=$this->order($main,'order',2,100000000);$t=$this->claim($main,$o);$this->assertSame('waiting_stock',$o->refresh()->delivery_phase);
        [$id,$d,$m]=$this->transfer($aux,$main,2,100000000);
        $this->signed('GET','/app/v3/gold-transfers?worker_id='.$this->worker)->assertOk()->assertJsonPath('data.0.id',$id);
        $this->signed('GET','/app/v3/gold-transfers?worker_id='.$this->other)->assertJsonCount(1,'data');
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
        [$id,$d,$m]=$this->transfer($source,$aux,2);
        $this->signed('PUT',"/app/v3/gold/bots/{$source->id}",['worker_id'=>$this->other,'action'=>'lease'])->assertOk();
        $wrongToken=[...$d,'worker_id'=>$this->other,'action'=>'cancel','bars_qty'=>3,'step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'unchanged'=>true]];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrongToken)->assertConflict();
        $this->lease($source,$this->worker);
        $this->signed('PUT',"/app/v3/gold/bots/{$source->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>5])->assertConflict();
        $cancel=[...$d,'worker_id'=>$this->other,'action'=>'cancel','step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'unchanged'=>false]];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertUnprocessable();$cancel['evidence']['unchanged']=true;
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertOk()->assertJsonPath('data.status','cancelled');
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertOk();$this->assertSame(5,GoldTradingService::available($source->fresh())['bars']);
        $this->assertSame(0,$aux->refresh()->gold_bar_qty);
    }
    public function test_transfer_invalid_snapshot_rolls_back_both_and_terminal_ack_after_takeover(): void {
        $from=$this->bot('import_main',10,0);$to=$this->bot('selling_sub',0,0);$this->lease($from);$this->lease($to);
        [$id,$d,$m]=$this->transfer($from,$to,3);$p=$this->transferReceipt($from,$to,$d,$m);$bad=$p;$bad['to_snapshot']['gold_bar_qty']=1000;
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$bad)->assertUnprocessable();$this->assertSame(10,$from->refresh()->gold_bar_qty);$this->assertSame(0,$to->refresh()->gold_bar_qty);
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();
        foreach([$from,$to] as $b) {$this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertOk();$this->lease($b,$this->other);}
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();$this->assertSame(7,$from->refresh()->gold_bar_qty);
        $retry=$d;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$retry)->assertOk()->assertJsonPath('data.status','completed');
        $retry['bars_qty']=2;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$retry)->assertConflict();
    }
    public function test_old_lease_metadata_does_not_exclude_v2_but_missing_order_checkpoint_cannot_be_replayed(): void {
        $b=$this->bot('selling_main,import_sub');$this->lease($b);$o=$this->order($b);$headers=['X-APP-KEY'=>'legacy-test'];
        $this->getJson('/app/v2/bots?type=selling_main',$headers)->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/app/v1/bots?type=selling_main',$headers)->assertOk()->assertJsonCount(1,'data');
        $this->putJson("/app/v2/gold-transactions/{$o->id}",['status'=>'processing','bot_id'=>$b->id,'gold_bar_qty'=>10,'gold_qty'=>100000000],$headers)->assertOk();
        $this->assertSame($b->id,(int)$o->refresh()->bot_id);$this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'lease'])->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim'])->assertConflict();
    }
    public function test_sale_and_stock_work_without_heartbeat_and_with_expired_old_worker_metadata(): void {
        $b=$this->bot();
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,'gold_bar_qty'=>10,'gold_qty'=>100000000])->assertOk();
        $o=$this->order($b);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,2,100000000,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $this->assertSame(8,$b->refresh()->gold_bar_qty);$this->assertSame(0,$b->gold_qty);
        $this->assertTrue($o->fresh()->hasDeliveryCheckpoint());
        $this->assertSame(1,InventoryMovement::where('transaction_id',$o->id)->where('movement_type','sale')->count());
    }
    public function test_checkpoint_resumes_without_ownership_but_wrong_bot_or_changed_terms_do_not(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim'])->assertOk()
            ->assertJsonPath('data.has_delivery_checkpoint',true);
        $phase=['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'phase','processing_token'=>$token,'step_id'=>(string)Str::uuid(),'character_id'=>1234,'phase'=>'trading'];
        $otherBot=$this->bot();$wrong=$phase;$wrong['bot_id']=$otherBot->id;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertConflict();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$phase)->assertOk();
        $p=$this->receipt($b,$o,$token,2,100000000,true);$p['worker_id']=$this->worker;
        $wrong=$p;$wrong['evidence']['confirmed']=false;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $o->update(['amount_vnd'=>1]);$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
        $o->update(['amount_vnd'=>100000]);$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertTrue($o->fresh()->hasDeliveryCheckpoint());
        $this->assertSame(8,$b->refresh()->gold_bar_qty);
    }
    public function test_transfer_recovery_keeps_exact_terms_and_receipt_without_ownership(): void {
        $from=$this->bot('import_main',10,0);$to=$this->bot('selling_sub',0,0);
        [$id,$d,$meta]=$this->transfer($from,$to,3);
        $resume=[...$d,'worker_id'=>$this->other];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$resume)->assertOk()->assertJsonPath('data.id',$id);
        $p=$this->transferReceipt($from,$to,$resume,$meta);
        $wrong=$p;$wrong['bars_qty']=$p['bars_qty']+1;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertConflict();
        $wrong=$p;$wrong['evidence']['confirmed']=false;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['bars_qty']=2;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertConflict();
        $wrong=$p;$wrong['from_snapshot']['gold_bar_qty']=-1;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertUnprocessable();
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();
        $wrong=$p;$wrong['worker_id']=$this->worker;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertConflict();
        $this->assertSame(7,$from->refresh()->gold_bar_qty);$this->assertSame(3,$to->refresh()->gold_bar_qty);
        $this->assertSame(0,InventoryMovement::where('transaction_type','gold_transfer')->sum('quantity_delta'));
        $this->assertSame(2,InventoryMovement::where('transaction_type','gold_transfer')->where('asset_type','pure_gold')->count());
    }
    public function test_waiting_stock_can_refresh_baseline_but_trading_cannot(): void {
        $b=$this->bot('selling_main',0,0);$this->lease($b);$o=$this->order($b,'order',2,0);$token=$this->claim($b,$o);
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,'gold_bar_qty'=>2,'gold_qty'=>0])->assertOk();
        $this->assertSame(['bars'=>0,'pure'=>0],$o->refresh()->delivery_data['progress']);$this->phase($b,$o,$token);
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>2,'gold_bar_qty'=>2,'gold_qty'=>0])->assertConflict();
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertOk();
        $this->assertTrue($o->refresh()->hasDeliveryCheckpoint());
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
    private function pendingRefund(Bot $bot,GoldTransaction $order,array $data=[]) {
        return $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",[
            'worker_id'=>$this->worker,'bot_id'=>$bot->id,'action'=>'refund_pending',
            'step_id'=>(string)Str::uuid(),'timeout_seconds'=>600,...$data]);
    }
    public function test_pending_gold_refund_credits_exact_amount_immediately_once_and_preserves_cancelled_contract(): void {
        $this->freezeTime();$b=$this->bot();$u=User::factory()->create(['balance'=>50000]);$o=$this->order($b,'order',2,0,$u);
        $o->update(['amount_vnd'=>11429,'created_at'=>now()->subSeconds(59)]);
        $p=['step_id'=>(string)Str::uuid(),'timeout_seconds'=>60];
        $this->pendingRefund($b,$o,$p)->assertConflict();$this->assertNull($o->fresh()->cancel_requested_at);
        $this->travel(1)->seconds();$b->update(['status'=>false]);
        $this->pendingRefund($b,$o,$p)->assertOk()->assertJsonPath('data.status','cancelled')->assertJsonPath('data.bot_id',null)
            ->assertJsonPath('ack_id',$p['step_id'])->assertJsonPath('data.refunded_at',fn($time)=>is_string($time));
        $after=$o->fresh()->getAttributes();$this->pendingRefund($b,$o,$p)->assertOk();$this->assertSame($after,$o->fresh()->getAttributes());
        $this->assertSame(61429,(int)$u->refresh()->balance);$this->assertNotNull($o->fresh()->cancel_requested_at);$this->assertNotNull($o->fresh()->refunded_at);
        $this->assertSame(['timeout_seconds'=>60,'refund_grace_minutes'=>0],$o->fresh()->delivery_data['timeout_policy']);
        $this->assertSame(1,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());
        $this->assertDatabaseHas('transactions',['idempotency_key'=>"gold-order-timeout-refund:{$o->id}",'amount'=>11429]);
        $this->pendingRefund($b,$o,[...$p,'timeout_seconds'=>61])->assertConflict();
        $this->travel(30)->minutes();$this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(61429,(int)$u->refresh()->balance);$this->assertSame(10,$b->refresh()->gold_bar_qty);$this->assertSame(100000000,$b->gold_qty);
    }
    public function test_pending_gold_refund_refuses_import_processing_wrong_bot_role_and_server(): void {
        $b=$this->bot();$u=User::factory()->create(['balance'=>50000]);$o=$this->order($b,'order',1,0,$u);$o->update(['created_at'=>now()->subHour()]);
        $this->claim($b,$o);$this->pendingRefund($b,$o)->assertConflict();$this->assertSame('processing',$o->fresh()->status);
        $importer=$this->bot('import_main',0,0);$import=$this->order($importer,'import',1,0,$u);$import->update(['created_at'=>now()->subHour()]);
        $this->pendingRefund($importer,$import)->assertUnprocessable();$this->pendingRefund($b,$import)->assertUnprocessable();
        $pending=$this->order($b,'order',1,0,$u);$pending->update(['bot_id'=>$b->id,'created_at'=>now()->subHour()]);
        $other=$this->bot();$this->pendingRefund($other,$pending)->assertConflict();$this->pendingRefund($importer,$pending)->assertConflict();
        $unassigned=$this->order($b,'order',1,0,$u);$unassigned->update(['created_at'=>now()->subHour()]);$this->pendingRefund($importer,$unassigned)->assertUnprocessable();
        $wrongServer=Server::create(['name'=>'refund-other','status'=>true]);$other->update(['server_id'=>$wrongServer->id]);
        $this->pendingRefund($other,$pending)->assertUnprocessable();
        foreach([$o,$import,$pending] as $order){$this->assertNull($order->fresh()->refunded_at);$this->assertNull($order->fresh()->cancel_requested_at);}
        $this->assertSame(50000,(int)$u->refresh()->balance);$this->assertDatabaseCount('transactions',0);
    }
    public function test_assigned_pending_gold_order_refunds_once_after_its_bot_role_is_changed_and_disabled(): void {
        $b=$this->bot();$u=User::factory()->create(['balance'=>50000]);$o=$this->order($b,'order',1,0,$u);
        $o->update(['bot_id'=>$b->id,'created_at'=>now()->subHour()]);$b->update(['type'=>'import_main,selling_sub','status'=>false]);
        $p=['step_id'=>(string)Str::uuid()];$this->pendingRefund($b,$o,$p)->assertOk()->assertJsonPath('data.status','cancelled')
            ->assertJsonPath('data.refunded_at',fn($time)=>is_string($time))->assertJsonPath('data.bot_id',$b->id);
        $this->pendingRefund($b,$o,$p)->assertOk();$this->assertSame(150000,(int)$u->refresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());$this->assertSame(10,$b->refresh()->gold_bar_qty);
    }
    public function test_pending_gold_refund_and_claim_race_cannot_restart_refunded_order(): void {
        $b=$this->bot();$u=User::factory()->create(['balance'=>50000]);$o=$this->order($b,'order',1,0,$u);$o->update(['created_at'=>now()->subHour()]);
        $this->pendingRefund($b,$o)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim'])->assertOk()
            ->assertJsonPath('data.status','cancelled')->assertJsonPath('data.has_delivery_checkpoint',false);
        $this->assertSame(150000,(int)$u->refresh()->balance);$this->assertSame(10,$b->refresh()->gold_bar_qty);
        $this->assertSame(1,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());
    }
    public function test_stale_pending_gold_delivery_progress_or_receipts_are_not_refundable(): void {
        $b=$this->bot();$u=User::factory()->create(['balance'=>50000]);
        foreach([['delivery_data'=>['claim'=>['marker'=>'checkpoint']]],['delivery_data'=>['progress'=>['bars'=>1,'pure'=>0]]],['delivery_data'=>['progress'=>['bars'=>0,'pure'=>1]]]] as $proof){
            $o=$this->order($b,'order',2,0,$u);$o->update([...$proof,'created_at'=>now()->subHour()]);
            $this->pendingRefund($b,$o)->assertConflict();$this->assertSame('pending',$o->fresh()->status);$this->assertNull($o->fresh()->refunded_at);
        }
        $o=$this->order($b,'order',1,0,$u);$o->update(['created_at'=>now()->subHour()]);
        InventoryMovement::create(['server_id'=>$b->server_id,'bot_id'=>$b->id,'bot_type'=>'bot','asset_type'=>'pure_gold','movement_type'=>'sale',
            'quantity_delta'=>-37000000,'balance_before'=>37000000,'balance_after'=>0,'transaction_id'=>$o->id,'transaction_type'=>'gold_transaction',
            'idempotency_key'=>'stale-gold-delivery-proof','source'=>'order','occurred_at'=>now()]);
        $this->pendingRefund($b,$o)->assertConflict();$this->assertNull($o->fresh()->cancel_requested_at);
        $this->assertSame(50000,(int)$u->refresh()->balance);$this->assertDatabaseCount('transactions',0);
    }
    public function test_pending_gold_refund_credit_failure_rolls_back_everything_and_can_retry_same_body(): void {
        $b=$this->bot();$u=User::factory()->create(['balance'=>TransactionService::MAX_BALANCE]);$o=$this->order($b,'order',1,0,$u);$o->update(['created_at'=>now()->subHour()]);
        $p=['step_id'=>(string)Str::uuid()];$this->pendingRefund($b,$o,$p)->assertUnprocessable();
        $this->assertSame('pending',$o->fresh()->status);$this->assertNull($o->fresh()->cancel_requested_at);$this->assertNull($o->fresh()->refunded_at);
        $this->assertSame(0,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());
        $this->assertSame(0,InventoryMovement::where('idempotency_key',"trading-v3:gold:{$o->id}:{$p['step_id']}:refund_pending")->count());
        $u->forceFill(['balance'=>50000])->save();$this->pendingRefund($b,$o,$p)->assertOk();$this->assertSame(150000,(int)$u->refresh()->balance);
    }
    public function test_gold_cron_remains_fallback_and_tool_does_not_bypass_cron_cancel_grace(): void {
        config(['trading.gold_order_pending_timeout_minutes'=>10,'trading.gold_order_refund_grace_minutes'=>5]);
        $b=$this->bot();$u=User::factory()->create(['balance'=>50000]);$o=$this->order($b,'order',1,0,$u);$o->update(['created_at'=>now()->subMinutes(11)]);
        $this->artisan('gold:cancel-stale')->assertSuccessful();$this->assertSame('cancelled',$o->fresh()->status);$this->assertNull($o->fresh()->refunded_at);
        $this->pendingRefund($b,$o)->assertConflict();$this->assertSame(50000,(int)$u->refresh()->balance);
        $this->travel(5)->minutes();$this->artisan('gold:cancel-stale')->assertSuccessful();$before=$o->fresh()->getAttributes();
        $this->pendingRefund($b,$o)->assertOk()->assertJsonPath('data.status','cancelled')->assertJsonPath('data.refunded_at',fn($time)=>is_string($time));
        $this->assertSame($before,$o->fresh()->getAttributes());$this->assertSame(150000,(int)$u->refresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());
    }
    public function test_pending_gold_refund_validates_uuid_seconds_and_uses_distinct_idempotency_namespace(): void {
        $b=$this->bot();$o=$this->order($b,'order',1,0);$o->update(['created_at'=>now()->subHour()]);
        foreach([0,86401,null] as $seconds)$this->pendingRefund($b,$o,['timeout_seconds'=>$seconds])->assertUnprocessable();
        $this->pendingRefund($b,$o,['step_id'=>null])->assertUnprocessable();$this->assertSame('pending',$o->fresh()->status);
        $id=(string)Str::uuid();InventoryMovement::create(['server_id'=>$b->server_id,'bot_id'=>$b->id,'bot_type'=>'bot','asset_type'=>'workflow',
            'movement_type'=>'phase','quantity_delta'=>0,'balance_before'=>0,'balance_after'=>0,'transaction_id'=>$o->id,'transaction_type'=>'gold_transaction',
            'idempotency_key'=>"trading-v3:gold:{$o->id}:{$id}:phase",'source'=>'app','meta'=>['payload_hash'=>'unrelated-old-phase'],'occurred_at'=>now()]);
        $this->pendingRefund($b,$o,['step_id'=>$id])->assertOk();
        $this->assertSame(1,InventoryMovement::where('idempotency_key',"trading-v3:gold:{$o->id}:{$id}:refund_pending")->count());
    }
    public function test_confirmed_no_effect_gold_trade_releases_to_pending_then_refunds_expired_order_once(): void {
        $b=$this->bot();$u=User::factory()->create(['balance'=>50000]);$o=$this->order($b,'order',1,0,$u);$o->update(['created_at'=>now()->subHour()]);
        $token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'release_unstarted','processing_token'=>$token,
            'step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'no_effect'=>true]];
        $wrong=$p;$wrong['evidence']['no_effect']=false;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['evidence']['confirmed']=false;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['bot_id']=$this->bot()->id;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertConflict();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.status','pending')->assertJsonPath('data.has_delivery_checkpoint',false)
            ->assertJsonPath('data.delivery_data.released_claim.step_id',$p['step_id'])->assertJsonPath('ack_id',$p['step_id']);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();$this->assertNull($o->fresh()->character_id);
        $this->assertArrayNotHasKey('claim',$o->fresh()->delivery_data);$this->assertSame(10,$b->refresh()->gold_bar_qty);
        $this->pendingRefund($b,$o)->assertOk()->assertJsonPath('data.refunded_at',fn($time)=>is_string($time));
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.status','cancelled');
        $this->assertSame(150000,(int)$u->refresh()->balance);$this->assertSame(1,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());
    }
    public function test_confirmed_gold_sale_accepts_small_pure_loss_and_external_bar_topup(): void {
        $b=$this->bot();$o=$this->order($b,'order',2,0);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,2,0,true);$p['bot_snapshot']['gold_qty']-=7;$p['bot_snapshot']['gold_bar_qty']+=5;
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(-74000000,(int)InventoryMovement::where('transaction_id',$o->id)->where('movement_type','sale')->sum('quantity_delta'));
        $this->assertSame(5*37000000-7,(int)InventoryMovement::where('movement_type','sync_correction')->sum('quantity_delta'));
        $this->assertSame(13,$b->refresh()->gold_bar_qty);$this->assertSame(99999993,$b->gold_qty);
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
    }
    public function test_confirmed_import_uses_order_quote_and_actual_capacity_despite_cached_full_stock(): void {
        $b=$this->bot('import_main',999,0);$u=User::factory()->create(['balance'=>1000]);$o=$this->order($b,'import',1,100,$u);
        $token=$this->claim($b,$o);$this->assertSame('waiting_stock',$o->refresh()->delivery_phase);
        // A safely parked order synchronizes observed room before reserving the customer trade.
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",['action'=>'stock','stock_version'=>1,'gold_bar_qty'=>4,'gold_qty'=>0])->assertOk();
        $this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,1,100,true);$p['bot_snapshot']['gold_bar_qty']=5;$p['bot_snapshot']['gold_qty']=99;
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();
        $this->assertSame(101000,(int)$u->refresh()->balance);$this->assertSame(1,Transaction::where('idempotency_key','gold-import-credit:'.$o->id)->count());
        $this->assertSame(37000100,(int)InventoryMovement::where('transaction_id',$o->id)->where('movement_type','import')->sum('quantity_delta'));
        $this->assertSame(-995*37000000-1,(int)InventoryMovement::where('movement_type','sync_correction')->sum('quantity_delta'));
        $this->assertSame(5,$b->refresh()->gold_bar_qty);$this->assertSame(99,$b->gold_qty);
    }
    public function test_stale_gold_receipt_settles_without_stock_regression_or_negative_ledger_balances(): void {
        $b=$this->bot();$o=$this->order($b,'order',2,0);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,2,0,true);$b->forceFill(['gold_qty'=>0,'gold_bar_qty'=>0,'stock_version'=>5])->save();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(0,$b->refresh()->gold_bar_qty);$this->assertSame(5,$b->stock_version);
        $movement=InventoryMovement::where('idempotency_key',"trading-v3:gold:{$o->id}:{$p['step_id']}:receipt")->firstOrFail();
        $this->assertSame(-74000000,$movement->quantity_delta);$this->assertSame('snapshot_already_current',$movement->meta['snapshot_resolution']);
        $this->assertSame(0,InventoryMovement::where('balance_before','<',0)->orWhere('balance_after','<',0)->count());
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
        $p['bot_snapshot']['stock_version']=6;$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
    }
    public function test_transfer_settles_exact_reserved_quantities_with_independent_inventory_changes(): void {
        $from=$this->bot('import_main',10,100);$to=$this->bot('selling_sub',0,100);[$id,$d,$meta]=$this->transfer($from,$to,3);
        $p=$this->transferReceipt($from,$to,$d,$meta);
        $p['from_snapshot']['gold_bar_qty']=12;$p['from_snapshot']['gold_qty']=99;
        $p['to_snapshot']['gold_bar_qty']=8;$p['to_snapshot']['gold_qty']=98;
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(0,(int)InventoryMovement::where('transaction_type','gold_transfer')->sum('quantity_delta'));
        $this->assertSame(-111000000,(int)InventoryMovement::where('movement_type','transfer_out')->sum('quantity_delta'));
        $this->assertSame(111000000,(int)InventoryMovement::where('movement_type','transfer_in')->sum('quantity_delta'));
        $this->assertSame(370000000-3,(int)InventoryMovement::where('movement_type','sync_correction')->sum('quantity_delta'));
        $this->assertSame(12,$from->refresh()->gold_bar_qty);$this->assertSame(8,$to->refresh()->gold_bar_qty);
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
    }
    public function test_transfer_receipt_preserves_newer_source_stock_but_applies_fresh_receiver_snapshot(): void {
        $from=$this->bot('import_main',10,0);$to=$this->bot('selling_sub',0,0);[$id,$d,$meta]=$this->transfer($from,$to,3);
        $p=$this->transferReceipt($from,$to,$d,$meta);$from->forceFill(['gold_bar_qty'=>50,'stock_version'=>10])->save();
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(50,$from->refresh()->gold_bar_qty);$this->assertSame(10,$from->stock_version);$this->assertSame(3,$to->refresh()->gold_bar_qty);
        $this->assertSame(0,(int)InventoryMovement::where('transaction_type','gold_transfer')->sum('quantity_delta'));
    }
    public function test_pending_gold_snapshot_requires_current_round_proof_then_allows_one_refresh(): void {
        $b=$this->bot();$o=$this->order($b,'order',2,0);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=$this->receipt($b,$o,$token,1,0,false);$p['bot_snapshot']['stock_version']=2;$session=(string)Str::uuid();
        $p['evidence']['intent']=['id'=>$p['step_id'],'order_id'=>$o->id,'trade_session_id'=>$session,'character_id'=>999,'customer_id'=>1234,'stock_snapshot_pending'=>true,
            'game_success'=>['intent_id'=>$p['step_id'],'session_id'=>$session,'character_id'=>999,'partner_id'=>1234,'bars_qty'=>1,'pure_gold_qty'=>0,'offer_verified'=>true,'outcome'=>'success','observed_at'=>now()->toIso8601String()]];
        $wrong=$p;$wrong['evidence']['intent']['game_success']['session_id']=(string)Str::uuid();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['evidence']['intent']['game_success']['offer_verified']=false;
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.delivery_data.progress.bars',1);
        $this->assertSame(10,$b->refresh()->gold_bar_qty);$this->assertSame(2,$b->stock_version);
        $this->assertTrue(InventoryMovement::where('movement_type','sale')->firstOrFail()->meta['snapshot_watermark_advanced']);
        $late=['action'=>'stock','stock_version'=>1,'gold_bar_qty'=>12,'gold_qty'=>100000000];
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",$late)->assertConflict();
        $this->assertSame(10,$b->refresh()->gold_bar_qty);$this->assertSame(2,$b->stock_version);
        $stock=['action'=>'stock','stock_version'=>3,'gold_bar_qty'=>14,'gold_qty'=>99999999];
        $this->signed('PUT',"/app/v3/gold/bots/{$b->id}",$stock)->assertOk();
        $this->assertSame(14,$b->refresh()->gold_bar_qty);$this->assertArrayNotHasKey('stock_refresh_pending_step_id',$o->refresh()->delivery_data);
        $stock['stock_version']=4;$this->signed('PUT',"/app/v3/gold/bots/{$b->id}",$stock)->assertConflict();
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
        $this->assertArrayNotHasKey('stock_refresh_pending_step_id',$o->refresh()->delivery_data);
    }
    public function test_pending_transfer_snapshot_requires_matching_proof_and_no_cancel_contradiction(): void {
        $from=$this->bot('import_main',10,0);$to=$this->bot('selling_sub',0,0);[$id,$d,$meta]=$this->transfer($from,$to,3);
        $p=$this->transferReceipt($from,$to,$d,$meta);$p['from_snapshot']['stock_version']=2;$p['to_snapshot']['stock_version']=3;$session=(string)Str::uuid();
        $p['evidence']['intent']=['id'=>$p['step_id'],'from_trade_session_id'=>$session,'from_character_id'=>100,'to_character_id'=>200,'stock_snapshot_pending'=>true,
            'from_game_success'=>['intent_id'=>$p['step_id'],'session_id'=>$session,'character_id'=>100,'partner_id'=>200,'bars_qty'=>3,'pure_gold_qty'=>0,'offer_verified'=>true,'outcome'=>'success','observed_at'=>now()->toIso8601String()]];
        $wrong=$p;$wrong['evidence']['intent']['to_game_cancelled']=true;
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertUnprocessable();
        $wrong=$p;$wrong['evidence']['intent']['from_game_success']['bars_qty']=2;
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertUnprocessable();
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(10,$from->refresh()->gold_bar_qty);$this->assertSame(0,$to->refresh()->gold_bar_qty);
        $this->assertSame(0,(int)InventoryMovement::where('transaction_type','gold_transfer')->sum('quantity_delta'));
        $this->assertSame(2,$from->stock_version);$this->assertSame(3,$to->stock_version);
        foreach([[$from,1,2,7],[$to,2,3,3]] as [$bot,$lateVersion,$watermark,$actualBars]) {
            $late=['action'=>'stock','stock_version'=>$lateVersion,'gold_bar_qty'=>50,'gold_qty'=>0];
            $this->signed('PUT',"/app/v3/gold/bots/{$bot->id}",$late)->assertConflict();
            $this->assertSame($watermark,$bot->refresh()->stock_version);
            $late['stock_version']=$watermark+1;$late['gold_bar_qty']=$actualBars;
            $this->signed('PUT',"/app/v3/gold/bots/{$bot->id}",$late)->assertOk();
            $this->assertSame($actualBars,$bot->refresh()->gold_bar_qty);
        }
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$p)->assertOk();
        $this->assertDatabaseCount('inventory_movements',$count);$this->assertSame(3,$from->refresh()->stock_version);$this->assertSame(4,$to->refresh()->stock_version);
    }
    public function test_next_claim_can_atomically_settle_frozen_receipt_while_its_original_http_ack_is_delayed(): void {
        $b=$this->bot();$first=$this->order($b,'order',2,0);$token=$this->claim($b,$first);$this->phase($b,$first,$token);
        $receipt=$this->receipt($b,$first,$token,2,0,true);
        $receipt['evidence']['intent']=['id'=>$receipt['step_id'],'order_id'=>$first->id,'nested'=>['frozen'=>true,'quantities'=>[2,0]]];
        $next=$this->order($b,'order',1,0);$path="/app/v3/gold-transactions/{$next->id}";
        $claim=['action'=>'start','bot_id'=>$b->id];
        $this->signed('PUT',$path,$claim)->assertConflict();
        $claim['pending_receipts']=[['order_id'=>$first->id,'payload'=>$receipt]];
        $this->signed('PUT',$path,$claim)->assertOk()->assertJsonPath('data.status','processing')
            ->assertJsonPath('receipt_acks.0.order_id',$first->id)->assertJsonPath('receipt_acks.0.step_id',$receipt['step_id'])
            ->assertJsonPath('receipt_acks.0.ack_id',$receipt['step_id'])->assertJsonPath('receipt_acks.0.data.status','completed');
        $this->assertSame('completed',$first->refresh()->status);$this->assertSame(8,$b->refresh()->gold_bar_qty);
        $count=InventoryMovement::count();
        $this->signed('PUT',$path,$claim)->assertOk()->assertJsonPath('receipt_acks.0.data.status','completed');
        // The delayed original sender must receive the same ACK with its exact original body.
        $this->signed('PUT',"/app/v3/gold-transactions/{$first->id}",$receipt)->assertOk()->assertJsonPath('ack_id',$receipt['step_id']);
        $this->assertDatabaseCount('inventory_movements',$count);
        $receipt['bot_snapshot']['gold_bar_qty']++;$this->signed('PUT',"/app/v3/gold-transactions/{$first->id}",$receipt)->assertConflict();
    }
    public function test_claim_bundle_accepts_already_committed_import_receipt_without_crediting_twice(): void {
        $b=$this->bot('import_main',0,0);$user=User::factory()->create(['balance'=>1000]);$first=$this->order($b,'import',1,0,$user);
        $token=$this->claim($b,$first);$this->phase($b,$first,$token);$receipt=$this->receipt($b,$first,$token,1,0,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$first->id}",$receipt)->assertOk();
        $next=$this->order($b,'import',1,0,$user);
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",['action'=>'claim','bot_id'=>$b->id,
            'pending_receipts'=>[['order_id'=>$first->id,'payload'=>$receipt]]])->assertOk()->assertJsonPath('data.status','processing')
            ->assertJsonPath('receipt_acks.0.ack_id',$receipt['step_id']);
        $this->assertSame(101000,(int)$user->refresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key','gold-import-credit:'.$first->id)->count());
        $this->assertSame(1,InventoryMovement::where('movement_type','import')->count());
    }
    public function test_claim_failure_rolls_back_bundled_receipt_inventory_and_import_wallet_together(): void {
        $b=$this->bot('import_main',0,0);$user=User::factory()->create(['balance'=>1000]);$first=$this->order($b,'import',1,0,$user);
        $token=$this->claim($b,$first);$this->phase($b,$first,$token);$receipt=$this->receipt($b,$first,$token,1,0,true);
        $next=$this->order($b,'import',1,0,$user);$next->update(['amount_vnd'=>0]);$count=InventoryMovement::count();
        $claim=['action'=>'start','bot_id'=>$b->id,'pending_receipts'=>[['order_id'=>$first->id,'payload'=>$receipt]]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$claim)->assertUnprocessable();
        $this->assertSame('processing',$first->refresh()->status);$this->assertSame('pending',$next->refresh()->status);
        $this->assertSame(0,$b->refresh()->gold_bar_qty);$this->assertSame(1000,(int)$user->refresh()->balance);
        $this->assertDatabaseCount('inventory_movements',$count);$this->assertDatabaseCount('transactions',0);
        $next->update(['amount_vnd'=>100000]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$claim)->assertOk()->assertJsonPath('data.status','processing');
        $this->assertSame(101000,(int)$user->refresh()->balance);
    }
    public function test_claim_bundle_rejects_wrong_bot_nonterminal_invalid_or_nested_receipts_without_mutation(): void {
        $b=$this->bot();$first=$this->order($b,'order',2,0);$token=$this->claim($b,$first);$this->phase($b,$first,$token);
        $receipt=$this->receipt($b,$first,$token,2,0,true);$next=$this->order($b,'order',1,0);$other=$this->bot();
        $claim=['action'=>'start','bot_id'=>$b->id,'pending_receipts'=>[['order_id'=>$first->id,'payload'=>$receipt]]];$count=InventoryMovement::count();
        foreach([
            ['bot_id'=>$other->id],['completed'=>false],['action'=>'phase'],['evidence'=>['kind'=>'sale','confirmed'=>false]],
            ['pending_receipts'=>[['order_id'=>$next->id,'payload'=>$receipt]]],
            ['bot_snapshot'=>['stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>1000]],
        ] as $bad) {
            $wrong=$claim;$wrong['pending_receipts'][0]['payload']=[...$receipt,...$bad];
            $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$wrong)->assertUnprocessable();
        }
        $wrong=$claim;$wrong['pending_receipts'][0]['order_id']=$next->id;
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$wrong)->assertUnprocessable();
        $wrong=$claim;$wrong['pending_receipts']=array_fill(0,17,$claim['pending_receipts'][0]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$wrong)->assertUnprocessable();
        $wrong=$receipt;$wrong['pending_receipts']=$claim['pending_receipts'];
        $this->signed('PUT',"/app/v3/gold-transactions/{$first->id}",$wrong)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements',$count);$this->assertSame('processing',$first->refresh()->status);
        $this->assertSame('pending',$next->refresh()->status);$this->assertSame(10,$b->refresh()->gold_bar_qty);
    }
    public function test_later_invalid_bundled_receipt_rolls_back_earlier_valid_receipt(): void {
        $b=$this->bot('import_main',0,0);$user=User::factory()->create(['balance'=>1000]);$first=$this->order($b,'import',1,0,$user);
        $token=$this->claim($b,$first);$this->phase($b,$first,$token);$receipt=$this->receipt($b,$first,$token,1,0,true);
        $unclaimed=$this->order($b,'import',1,0,$user);$next=$this->order($b,'import',1,0,$user);
        $invalid=$receipt;$invalid['step_id']=(string)Str::uuid();$count=InventoryMovement::count();
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",['action'=>'start','bot_id'=>$b->id,'pending_receipts'=>[
            ['order_id'=>$first->id,'payload'=>$receipt],['order_id'=>$unclaimed->id,'payload'=>$invalid],
        ]])->assertConflict();
        $this->assertSame('processing',$first->refresh()->status);$this->assertSame('pending',$unclaimed->refresh()->status);
        $this->assertSame('pending',$next->refresh()->status);$this->assertSame(0,$b->refresh()->gold_bar_qty);
        $this->assertSame(1000,(int)$user->refresh()->balance);$this->assertDatabaseCount('transactions',0);
        $this->assertDatabaseCount('inventory_movements',$count);
    }
    public function test_claim_bundle_preserves_pending_snapshot_proof_and_exact_standalone_retry(): void {
        $b=$this->bot();$first=$this->order($b,'order',2,0);$token=$this->claim($b,$first);$this->phase($b,$first,$token);
        $receipt=$this->receipt($b,$first,$token,2,0,true);$session=(string)Str::uuid();
        $receipt['evidence']['intent']=['id'=>$receipt['step_id'],'order_id'=>$first->id,'trade_session_id'=>$session,
            'character_id'=>999,'customer_id'=>1234,'stock_snapshot_pending'=>true,
            'game_success'=>['intent_id'=>$receipt['step_id'],'session_id'=>$session,'character_id'=>999,'partner_id'=>1234,
                'bars_qty'=>2,'pure_gold_qty'=>0,'offer_verified'=>true,'outcome'=>'success','observed_at'=>now()->toIso8601String()]];
        $next=$this->order($b,'order',1,0);
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",['action'=>'start','bot_id'=>$b->id,
            'pending_receipts'=>[['order_id'=>$first->id,'payload'=>$receipt]]])->assertOk()
            ->assertJsonPath('data.status','processing')->assertJsonPath('receipt_acks.0.ack_id',$receipt['step_id']);
        $this->assertSame(10,$b->refresh()->gold_bar_qty);$this->assertSame(1,$b->stock_version);
        $this->assertSame('snapshot_pending',InventoryMovement::where('movement_type','sale')->firstOrFail()->meta['snapshot_resolution']);
        $count=InventoryMovement::count();
        $this->signed('PUT',"/app/v3/gold-transactions/{$first->id}",$receipt)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertDatabaseCount('inventory_movements',$count);
    }
    public function test_late_duplicate_predecessor_cannot_overwrite_progress_of_the_next_order(): void {
        $b=$this->bot();$first=$this->order($b,'order',1,0);$token=$this->claim($b,$first);$this->phase($b,$first,$token);
        $receipt=$this->receipt($b,$first,$token,1,0,true);$next=$this->order($b,'order',2,0);
        $claim=['action'=>'start','bot_id'=>$b->id,'pending_receipts'=>[['order_id'=>$first->id,'payload'=>$receipt]]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$claim)->assertOk();
        $this->phase($b,$next,$token);$nextReceipt=$this->receipt($b,$next,$token,1,0,false);
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$nextReceipt)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$first->id}",$receipt)->assertOk()->assertJsonPath('data.id',$first->id);
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$claim)->assertOk()->assertJsonPath('data.delivery_data.progress.bars',1);
        $this->assertSame(8,$b->refresh()->gold_bar_qty);$this->assertSame('processing',$next->refresh()->status);
        $this->assertSame(-74000000,(int)InventoryMovement::where('movement_type','sale')->sum('quantity_delta'));
    }
    public function test_gold_release_unstarted_refuses_partial_delivery_and_acknowledged_receipts(): void {
        $b=$this->bot();$o=$this->order($b,'order',2,0);$token=$this->claim($b,$o);$this->phase($b,$o,$token);
        $p=['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'release_unstarted','processing_token'=>$token,
            'step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'no_effect'=>true]];
        $receipt=$this->receipt($b,$o,$token,1,0,false);$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$receipt)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
        // Even a stale progress label cannot release an already acknowledged game delivery.
        $details=$o->fresh()->delivery_data;$details['progress']=['bars'=>0,'pure'=>0];unset($details['last_step_id']);$o->update(['delivery_data'=>$details]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$p)->assertConflict();
        $this->assertSame('processing',$o->fresh()->status);$this->assertTrue($o->fresh()->hasDeliveryCheckpoint());$this->assertSame(9,$b->refresh()->gold_bar_qty);
    }
    public function test_settled_large_sale_wait_does_not_reserve_stock_or_block_a_smaller_customer(): void {
        $main=$this->bot('selling_main',5,0);$large=$this->order($main,'order',10,0);$token=$this->claim($main,$large);
        $this->assertTrue(GoldTradingService::settledStockWait($large->refresh()));
        $quote=$large->delivery_data['claim'];
        $this->assertSame(5,GoldTradingService::available($main)['bars']);
        $this->signed('GET','/app/v3/gold/bots')->assertJsonPath('data.0.available.bars',5);
        $small=$this->order($main,'order',3,0);$this->claim($main,$small);$this->phase($main,$small,$token);
        $this->assertSame(2,GoldTradingService::available($main)['bars']);
        $this->signed('PUT',"/app/v3/gold-transactions/{$large->id}",['action'=>'start','bot_id'=>$main->id])->assertConflict();
        $receipt=$this->receipt($main,$small,$token,3,0,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$small->id}",$receipt)->assertOk();
        $this->assertSame(2,$main->refresh()->gold_bar_qty);
        $this->signed('PUT',"/app/v3/gold-transactions/{$large->id}",['action'=>'start','bot_id'=>$main->id])->assertOk()
            ->assertJsonPath('data.delivery_phase','waiting_stock')->assertJsonPath('data.delivery_data.stock_wait_settled',true);
        $this->assertSame($quote,$large->refresh()->delivery_data['claim']);
        $this->assertSame(['bars'=>0,'pure'=>0],$large->delivery_data['progress']);
        $this->assertTrue(GoldTradingService::hasActiveDelivery($main)); // Legacy writes stay protected.
    }
    public function test_parked_sale_resumes_only_after_refill_and_keeps_actual_transfer_exclusive(): void {
        $main=$this->bot('selling_main',2,0);$aux=$this->bot('import_sub',20,0);
        // The auxiliary's own waiting point is unrelated to the assigned main's destination.
        $aux->update(['map_id'=>'','area_number'=>'','coordinates'=>'']);
        $large=$this->order($main,'order',10,0);$token=$this->claim($main,$large);
        [$id,$claim,$meta]=$this->transfer($aux,$main,8);
        $small=$this->order($main,'order',1,0);
        foreach([$small,$large] as $order) $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",['action'=>'start','bot_id'=>$main->id])->assertConflict();
        $receipt=$this->transferReceipt($aux,$main,$claim,$meta);
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$receipt)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$large->id}",['action'=>'start','bot_id'=>$main->id])->assertOk()
            ->assertJsonPath('data.delivery_phase','waiting_customer');
        $this->assertArrayNotHasKey('stock_wait_settled',$large->refresh()->delivery_data);
        $this->signed('PUT',"/app/v3/gold-transactions/{$small->id}",['action'=>'start','bot_id'=>$main->id])->assertConflict();
        $this->phase($main,$large,$token);$sale=$this->receipt($main,$large,$token,10,0,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$large->id}",$sale)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(0,$main->refresh()->gold_bar_qty);$this->assertSame(12,$aux->refresh()->gold_bar_qty);
    }
    public function test_explicit_stock_park_requires_no_effect_proof_and_duplicate_ack_cannot_repark_resumed_order(): void {
        $main=$this->bot('selling_main',5,0);$order=$this->order($main,'order',3,0);$token=$this->claim($main,$order);$this->phase($main,$order,$token);
        $park=['action'=>'phase','bot_id'=>$main->id,'step_id'=>(string)Str::uuid(),'phase'=>'waiting_stock'];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$park)->assertConflict();
        $this->assertSame('trading',$order->refresh()->delivery_phase);
        $park['evidence']=['confirmed'=>true,'no_effect'=>true];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$park)->assertOk()->assertJsonPath('data.delivery_data.stock_wait_settled',true);
        $this->assertSame(5,GoldTradingService::available($main)['bars']);
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",['action'=>'start','bot_id'=>$main->id,'character_id'=>1234])->assertOk()
            ->assertJsonPath('data.delivery_phase','waiting_customer');
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$park)->assertOk()->assertJsonPath('data.delivery_phase','waiting_customer');
        $this->assertArrayNotHasKey('stock_wait_settled',$order->refresh()->delivery_data);
        $changed=$park;$changed['phase']='trading';$this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$changed)->assertConflict();
        $this->assertSame(2,GoldTradingService::available($main)['bars']);
    }
    public function test_parked_import_room_is_available_to_smaller_import_but_capacity_is_rechecked(): void {
        $main=$this->bot('import_main',998,0);$large=$this->order($main,'import',2,0);$token=$this->claim($main,$large);
        $this->assertTrue(GoldTradingService::settledStockWait($large->refresh()));$this->assertSame(1,GoldTradingService::receiveRoom($main));
        $this->signed('GET','/app/v3/gold/bots')->assertJsonPath('data.0.receive_room',1);
        $small=$this->order($main,'import',1,0);$this->claim($main,$small);$this->phase($main,$small,$token);
        $this->assertSame(0,GoldTradingService::receiveRoom($main));
        $receipt=$this->receipt($main,$small,$token,1,0,true);$this->signed('PUT',"/app/v3/gold-transactions/{$small->id}",$receipt)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$large->id}",['action'=>'start','bot_id'=>$main->id])->assertOk()->assertJsonPath('data.delivery_phase','waiting_stock');
        $phase=['action'=>'phase','bot_id'=>$main->id,'step_id'=>(string)Str::uuid(),'phase'=>'trading','character_id'=>1234];
        $this->signed('PUT',"/app/v3/gold-transactions/{$large->id}",$phase)->assertConflict();
        $this->assertSame(999,$main->refresh()->gold_bar_qty);
    }
    public function test_partial_settled_wait_keeps_customer_reservation_but_can_refill(): void {
        $main=$this->bot('selling_main',3,0);$aux=$this->bot('selling_sub',10,0);$order=$this->order($main,'order',3,0);
        $token=$this->claim($main,$order);$this->phase($main,$order,$token);
        $receipt=$this->receipt($main,$order,$token,1,0,false);$this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$receipt)->assertOk();
        $park=['action'=>'phase','bot_id'=>$main->id,'step_id'=>(string)Str::uuid(),'phase'=>'waiting_stock','evidence'=>['confirmed'=>true,'no_effect'=>true]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$park)->assertOk()->assertJsonPath('data.delivery_data.stock_transfer_settled',true);
        $this->assertFalse(GoldTradingService::settledStockWait($order->refresh()));$this->assertSame(0,GoldTradingService::available($main->refresh())['bars']);
        $small=$this->order($main,'order',1,0);$this->signed('PUT',"/app/v3/gold-transactions/{$small->id}",['action'=>'start','bot_id'=>$main->id])->assertConflict();
        [$id,$claim,$meta]=$this->transfer($aux,$main,1);$transfer=$this->transferReceipt($aux,$main,$claim,$meta);
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$transfer)->assertOk();
        $this->phase($main,$order,$token);$final=$this->receipt($main,$order,$token,2,0,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$final)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(1,$main->refresh()->gold_bar_qty);
    }
    public function test_stale_or_unproven_wait_cannot_release_a_customer_or_transfer_gate(): void {
        $main=$this->bot('selling_main',5,0);$aux=$this->bot('selling_sub',10,0);$order=$this->order($main,'order',10,0);$this->claim($main,$order);
        $order->update(['amount_vnd'=>100001]); // Frozen quote invalidates the parked marker.
        $this->assertFalse(GoldTradingService::settledStockWait($order->refresh()));
        $small=$this->order($main,'order',1,0);$this->signed('PUT',"/app/v3/gold-transactions/{$small->id}",['action'=>'start','bot_id'=>$main->id])->assertConflict();
        $this->signed('PUT','/app/v3/gold-transfers/'.Str::uuid(),['action'=>'claim','from_bot_id'=>$aux->id,'to_bot_id'=>$main->id,'bars_qty'=>1])->assertConflict();
        $order->update(['amount_vnd'=>100000]);$details=$order->delivery_data;unset($details['stock_wait_settled']);$order->update(['delivery_data'=>$details]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$small->id}",['action'=>'start','bot_id'=>$main->id])->assertConflict();
        $this->signed('PUT',"/app/v3/gold/bots/{$main->id}",['action'=>'stock','stock_version'=>1,'gold_bar_qty'=>5,'gold_qty'=>0])->assertConflict();
    }
    public function test_parked_order_cannot_accept_receipt_or_pending_refund_before_resume(): void {
        $main=$this->bot('selling_main',0,0);$order=$this->order($main,'order',1,0);$token=$this->claim($main,$order);
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",['action'=>'start','bot_id'=>$main->id,'character_id'=>1234])->assertOk();
        $receipt=$this->receipt($main,$order,$token,1,0,true);$receipt['bot_snapshot']['gold_bar_qty']=0;
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$receipt)->assertConflict();
        $order->update(['created_at'=>now()->subHours(2)]);$this->pendingRefund($main,$order)->assertConflict();
        $this->assertSame('processing',$order->refresh()->status);$this->assertSame(0,InventoryMovement::where('movement_type','sale')->count());
    }
    public function test_bar_only_transfer_cancel_ignores_pure_gold_drift_but_not_bars_or_stock_version(): void {
        $from=$this->bot('import_main',5,100);$to=$this->bot('import_sub',0,100);[$id,$claim,$meta]=$this->transfer($from,$to,1);
        $cancel=[...$claim,'action'=>'cancel','step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'unchanged'=>true]];
        $from->update(['gold_qty'=>93]);$to->update(['gold_qty'=>98]);
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertOk()->assertJsonPath('data.status','cancelled');
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertOk();
        [$id2,$claim2,$meta2]=$this->transfer($from,$to,1,1);$mixed=[...$claim2,'action'=>'cancel','step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'unchanged'=>true]];
        $from->update(['gold_qty'=>92]);$this->signed('PUT',"/app/v3/gold-transfers/{$id2}",$mixed)->assertConflict();
        $from->forceFill(['gold_qty'=>93,'stock_version'=>1])->save();$this->signed('PUT',"/app/v3/gold-transfers/{$id2}",$mixed)->assertConflict();
        $from->forceFill(['stock_version'=>0,'gold_bar_qty'=>4])->save();$this->signed('PUT',"/app/v3/gold-transfers/{$id2}",$mixed)->assertConflict();
        $this->assertSame('claimed',GoldTradingService::activeTransfers()->sole()->meta['status']);
    }
    public function test_delayed_receipt_bundle_settles_one_sale_while_an_older_large_order_stays_parked(): void {
        $main=$this->bot('selling_main',5,0);$large=$this->order($main,'order',10,0);$token=$this->claim($main,$large);
        $first=$this->order($main,'order',2,0);$this->claim($main,$first);$this->phase($main,$first,$token);
        $receipt=$this->receipt($main,$first,$token,2,0,true);$next=$this->order($main,'order',3,0);
        $start=['action'=>'start','bot_id'=>$main->id,'pending_receipts'=>[['order_id'=>$first->id,'payload'=>$receipt]]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$start)->assertOk()->assertJsonPath('data.delivery_phase','waiting_customer')
            ->assertJsonPath('receipt_acks.0.ack_id',$receipt['step_id']);
        $this->assertSame(3,$main->refresh()->gold_bar_qty);$this->assertTrue(GoldTradingService::settledStockWait($large->refresh()));
        $this->assertSame(0,GoldTradingService::available($main)['bars']);
        $this->signed('PUT',"/app/v3/gold-transactions/{$first->id}",$receipt)->assertOk();
        $this->phase($main,$next,$token);$final=$this->receipt($main,$next,$token,3,0,true);
        // A changed quote on the parked order blocks its future resume, not an already confirmed sale's receipt.
        $large->update(['amount_vnd'=>100001]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$final)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(0,$main->refresh()->gold_bar_qty);$this->assertSame(2,InventoryMovement::where('movement_type','sale')->count());
        $this->assertSame(['bars'=>0,'pure'=>0],$large->refresh()->delivery_data['progress']);
    }
    public function test_next_customer_claim_can_settle_frozen_transfer_receipt_before_its_http_ack(): void {
        $aux=$this->bot('selling_sub',5,0);$main=$this->bot('selling_main',0,0);[$id,$claim,$meta]=$this->transfer($aux,$main,3);
        $receipt=$this->transferReceipt($aux,$main,$claim,$meta);$order=$this->order($main,'order',2,0);
        $start=['action'=>'start','bot_id'=>$main->id,'character_id'=>1234,'pending_transfers'=>[['transfer_id'=>$id,'payload'=>$receipt]]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",['action'=>'start','bot_id'=>$main->id])->assertConflict();
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$start)->assertOk()->assertJsonPath('data.delivery_phase','waiting_customer')
            ->assertJsonPath('transfer_acks.0.transfer_id',$id)->assertJsonPath('transfer_acks.0.ack_id',$receipt['step_id'])
            ->assertJsonPath('transfer_acks.0.data.status','completed');
        $this->assertSame(2,$aux->refresh()->gold_bar_qty);$this->assertSame(3,$main->refresh()->gold_bar_qty);
        $this->assertSame(1,GoldTradingService::available($main)['bars']);$this->assertCount(0,GoldTradingService::activeTransfers());
        $count=InventoryMovement::count();
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$receipt)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$start)->assertOk();
        $this->assertDatabaseCount('inventory_movements',$count);
        $this->phase($main,$order,'');$sale=$this->receipt($main,$order,'',2,0,true);unset($sale['processing_token']);
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$sale)->assertOk();
        $this->assertSame(1,$main->refresh()->gold_bar_qty);$this->assertDatabaseCount('transactions',0);
    }
    public function test_failed_new_claim_rolls_back_bundled_transfer_and_both_inventories(): void {
        $aux=$this->bot('import_sub',5,0);$main=$this->bot('selling_main',0,0);[$id,$claim,$meta]=$this->transfer($aux,$main,3);
        $receipt=$this->transferReceipt($aux,$main,$claim,$meta);$order=$this->order($main,'order',2,0);$main->update(['status'=>false]);
        $count=InventoryMovement::count();$start=['action'=>'start','bot_id'=>$main->id,'pending_transfers'=>[['transfer_id'=>$id,'payload'=>$receipt]]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$start)->assertConflict();
        $this->assertSame('claimed',GoldTradingService::activeTransfers()->sole()->meta['status']);
        $this->assertSame(5,$aux->refresh()->gold_bar_qty);$this->assertSame(0,$main->refresh()->gold_bar_qty);
        $this->assertSame('pending',$order->refresh()->status);$this->assertDatabaseCount('inventory_movements',$count);
        // A disabled new order does not stop independent acknowledgement of the confirmed game effect.
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$receipt)->assertOk();
        $this->assertSame(3,$main->refresh()->gold_bar_qty);
    }
    public function test_invalid_order_receipt_after_transfer_receipt_rolls_back_the_whole_start_bundle(): void {
        $aux=$this->bot('selling_sub',5,0);$main=$this->bot('selling_main',0,0);[$id,$claim,$meta]=$this->transfer($aux,$main,3);
        $transfer=$this->transferReceipt($aux,$main,$claim,$meta);$unstarted=$this->order($main,'order',1,0);$next=$this->order($main,'order',1,0);
        $receipt=$this->receipt($main,$unstarted,(string)Str::uuid(),1,0,true);$receipt['bot_snapshot']['gold_bar_qty']=2;
        $start=['action'=>'start','bot_id'=>$main->id,'pending_transfers'=>[['transfer_id'=>$id,'payload'=>$transfer]],
            'pending_receipts'=>[['order_id'=>$unstarted->id,'payload'=>$receipt]]];$count=InventoryMovement::count();
        $this->signed('PUT',"/app/v3/gold-transactions/{$next->id}",$start)->assertConflict();
        $this->assertSame('claimed',GoldTradingService::activeTransfers()->sole()->meta['status']);
        $this->assertSame(5,$aux->refresh()->gold_bar_qty);$this->assertSame(0,$main->refresh()->gold_bar_qty);
        $this->assertSame('pending',$next->refresh()->status);$this->assertDatabaseCount('inventory_movements',$count);
    }
    public function test_transfer_claim_bundle_rejects_wrong_bot_false_proof_nested_payload_and_changed_body(): void {
        $aux=$this->bot('selling_sub',5,0);$main=$this->bot('selling_main',0,0);$other=$this->bot('selling_main',3,0);
        [$id,$claim,$meta]=$this->transfer($aux,$main,3);$receipt=$this->transferReceipt($aux,$main,$claim,$meta);
        $order=$this->order($main,'order',1,0);$otherOrder=$this->order($other,'order',1,0);
        $start=['action'=>'start','bot_id'=>$main->id,'pending_transfers'=>[['transfer_id'=>$id,'payload'=>$receipt]]];
        $wrong=$start;$wrong['bot_id']=$other->id;$this->signed('PUT',"/app/v3/gold-transactions/{$otherOrder->id}",$wrong)->assertUnprocessable();
        $wrong=$start;$wrong['pending_transfers'][0]['payload']['evidence']['confirmed']=false;$this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$wrong)->assertUnprocessable();
        $wrong=$start;$wrong['pending_transfers'][0]['payload']['pending_transfers']=[['transfer_id'=>$id,'payload'=>$receipt]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$wrong)->assertUnprocessable();
        $wrong=$start;$wrong['pending_transfers'][0]['payload']['to_bot_id']=$aux->id;
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$wrong)->assertUnprocessable();
        $this->assertSame('claimed',GoldTradingService::activeTransfers()->sole()->meta['status']);
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$receipt)->assertOk();
        $wrong=$start;$wrong['pending_transfers'][0]['payload']['to_snapshot']['gold_qty']=1;
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$wrong)->assertConflict();
        $this->assertSame('pending',$order->refresh()->status);$this->assertSame(3,$main->refresh()->gold_bar_qty);
    }
    public function test_transfer_bundle_preserves_pending_snapshot_proof_watermark_and_standalone_retry(): void {
        $aux=$this->bot('selling_sub',5,0);$main=$this->bot('selling_main',0,0);[$id,$claim,$meta]=$this->transfer($aux,$main,3);
        $receipt=$this->transferReceipt($aux,$main,$claim,$meta);$session=(string)Str::uuid();
        $receipt['from_snapshot']['stock_version']=2;$receipt['to_snapshot']['stock_version']=3;
        $receipt['evidence']['intent']=['id'=>$receipt['step_id'],'from_trade_session_id'=>$session,'from_character_id'=>100,'to_character_id'=>200,'stock_snapshot_pending'=>true,
            'from_game_success'=>['intent_id'=>$receipt['step_id'],'session_id'=>$session,'character_id'=>100,'partner_id'=>200,'bars_qty'=>3,'pure_gold_qty'=>0,'offer_verified'=>true,'outcome'=>'success','observed_at'=>now()->toIso8601String()]];
        $order=$this->order($main,'order',2,0);$start=['action'=>'start','bot_id'=>$main->id,'pending_transfers'=>[['transfer_id'=>$id,'payload'=>$receipt]]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$start)->assertOk()->assertJsonPath('data.delivery_phase','waiting_stock');
        $this->assertSame(0,$main->refresh()->gold_bar_qty);$this->assertSame(3,$main->stock_version);$this->assertSame(2,$aux->refresh()->stock_version);
        $late=['action'=>'stock','stock_version'=>2,'gold_bar_qty'=>0,'gold_qty'=>0];
        $this->signed('PUT',"/app/v3/gold/bots/{$main->id}",$late)->assertConflict();
        $late['stock_version']=4;$late['gold_bar_qty']=3;$this->signed('PUT',"/app/v3/gold/bots/{$main->id}",$late)->assertOk();
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$receipt)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$start)->assertOk()->assertJsonPath('data.delivery_phase','waiting_customer');
        $this->assertDatabaseCount('inventory_movements',$count);$this->assertSame(3,$main->refresh()->gold_bar_qty);$this->assertSame(4,$main->stock_version);
    }
    public function test_parked_large_order_resumes_one_capacity_sized_trade_then_refills_the_exact_remainder(): void {
        $main=$this->bot('selling_main',998,0);$aux=$this->bot('selling_sub',1,0);$order=$this->order($main,'order',1000,0);
        $token=$this->claim($main,$order);$this->assertTrue(GoldTradingService::settledStockWait($order->refresh()));
        $this->signed('PUT',"/app/v3/gold/bots/{$main->id}",['action'=>'stock','stock_version'=>1,'gold_bar_qty'=>999,'gold_qty'=>0])->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",['action'=>'start','bot_id'=>$main->id])->assertOk()->assertJsonPath('data.delivery_phase','waiting_customer');
        $this->phase($main,$order,$token);$partial=$this->receipt($main,$order,$token,999,0,false);
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$partial)->assertOk()->assertJsonPath('data.remaining.bars',1);
        $park=['action'=>'phase','bot_id'=>$main->id,'step_id'=>(string)Str::uuid(),'phase'=>'waiting_stock','evidence'=>['confirmed'=>true,'no_effect'=>true]];
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$park)->assertOk()->assertJsonPath('data.delivery_data.stock_transfer_settled',true);
        [$id,$claim,$meta]=$this->transfer($aux,$main,1);$transfer=$this->transferReceipt($aux,$main,$claim,$meta);
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$transfer)->assertOk();
        $this->phase($main,$order,$token);$final=$this->receipt($main,$order,$token,1,0,true);
        $this->signed('PUT',"/app/v3/gold-transactions/{$order->id}",$final)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(0,$main->refresh()->gold_bar_qty);$this->assertSame(0,$aux->refresh()->gold_bar_qty);
        $this->assertSame(-1000*37000000,(int)InventoryMovement::where('movement_type','sale')->sum('quantity_delta'));
    }
}
