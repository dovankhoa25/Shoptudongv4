<?php
namespace Tests\Feature;
use App\Events\TradingBotActivity;
use App\Models\{GemBot,GemTransaction,Server,ServerGameLogin,User};
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;
class TradingV3Test extends TestCase {
    use RefreshDatabase;
    private string $worker='00000000-0000-4000-8000-000000000001';
    private string $secret='test-only-secret-with-at-least-32-characters';
    protected function setUp(): void {
        parent::setUp();config()->set('trading_v3',require base_path('config/trading_v3.php'));config()->set('trading_v3.enabled',true);config()->set('trading_v3.hmac_secret',$this->secret);
        config()->set('services.app_api_key','legacy-test');Event::fake();
    }
    private function callSigned(string $method,string $path,array $data=[],?array $headers=null) {
        $body=in_array($method,['GET','HEAD'])?'':json_encode($data);
        $time=(string)time();$nonce=bin2hex(random_bytes(16));$id='nro-trading-v3';
        $sig=hash_hmac('sha256',implode("\n",[$method,$path,$id,$time,$nonce,hash('sha256',$body)]),$this->secret);
        $headers??=['X-Tool-Id'=>$id,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,'X-Tool-Signature'=>$sig];
        return $method==='GET' ? $this->get($path,[...$headers,'Accept'=>'application/json']) : $this->json($method,$path,$data,$headers);
    }
    private function bot(bool $enabled=true): GemBot {
        $server=Server::create(['name'=>'business-server','status'=>true]);
        $loginId=(int)\Illuminate\Support\Facades\DB::table('server_game_login')->max('id')+1;
        \Illuminate\Support\Facades\DB::table('server_game_login')->insert(['id'=>$loginId,'name'=>'actual-login','ip'=>'test.example','port'=>'14445']);
        $login=ServerGameLogin::findOrFail($loginId);
        return GemBot::create(['name'=>'gem-bot','account_name'=>'test-bot','account_password'=>'test-password',
            'server_id'=>$server->id,'server_game_id'=>$login->id,'gem_qty'=>100,'gift_ticket_qty'=>10,
            'consign_item_qty'=>3,'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'41','coordinates'=>'652-336',
            'status'=>$enabled]);
    }
    private function lease(GemBot $b): void {
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'lease'])->assertOk();
    }
    private function order(GemBot $b,string $method='gift_ticket'): GemTransaction {
        return GemTransaction::create(['user_id'=>User::factory()->create()->id,'server_id'=>$b->server_id,
            'character_name'=>'lezit1','gem_qty'=>20,'amount_vnd'=>10000,'price_at_transaction'=>20,
            'delivery_method'=>$method,'status'=>'pending','updated_by'=>'web']);
    }
    private function claim(GemBot $b,GemTransaction $o): string {
        $result=$this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'claim']);
        $result->assertOk()->assertJsonPath('data.has_delivery_checkpoint',true);return (string)Str::uuid(); // Legacy wire field only.
    }
    private function receipt(GemBot $b,string $token,int $version,bool $done=false): array {
        return ['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'receipt','processing_token'=>$token,
            'character_id'=>1234,'step_id'=>(string)Str::uuid(),'gem_spent_qty'=>10,'delivered_gem_qty'=>10,
            'tickets_used_qty'=>1,'completed'=>$done,'bot_snapshot'=>['stock_version'=>$version,
                'gem_qty'=>100-$version*10,'gift_ticket_qty'=>10-$version,'consign_item_qty'=>3],
            'evidence'=>['kind'=>'gift','confirmed'=>true]];
    }
    public function test_hmac_rejects_unsigned_and_wrong_body_and_replay(): void {
        $this->getJson('/app/v3/config')->assertUnauthorized();
        $time=(string)time();$nonce=bin2hex(random_bytes(16));$path='/app/v3/config';$id='nro-trading-v3';
        $h=['X-Tool-Id'=>$id,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,
            'X-Tool-Signature'=>hash_hmac('sha256',implode("\n",['GET',$path,$id,$time,$nonce,hash('sha256','')]),$this->secret)];
        $this->callSigned('GET',$path,[],$h)->assertOk()->assertHeader('Cache-Control','no-store, private');$this->callSigned('GET',$path,[],$h)->assertConflict();
        $h['X-Tool-Nonce']=bin2hex(random_bytes(16));$this->callSigned('GET',$path,[],$h)->assertUnauthorized();
    }
    public function test_full_list_keeps_disabled_bots_and_resolves_database_login(): void {
        $b=$this->bot(false);$this->callSigned('GET','/app/v3/gem/bots')->assertOk()->assertJsonPath('data.0.id',$b->id)
            ->assertJsonPath('data.0.status',false)->assertJsonPath('data.0.server_id',$b->server_id)
            ->assertJsonPath('data.0.login.ip','test.example')->assertJsonPath('data.0.login.port',14445);
    }
    public function test_manual_completed_detail_is_readonly_with_stale_delivery_progress(): void {
        $b=$this->bot(false);$beforeBot=$b->fresh()->getAttributes();
        foreach(['gift_ticket'=>'gifting','consignment'=>'giving_item'] as $method=>$phase) {
            $o=$this->order($b,$method);
            $o->forceFill(['bot_id'=>$b->id,'status'=>'completed','delivery_phase'=>$phase,
                'completed_at'=>null,'bot_gem_spent_qty'=>0,'delivered_gem_qty'=>0,'tickets_used_qty'=>0])->save();
            $before=$o->fresh()->getAttributes();$wallet=$o->user->fresh()->getAttributes();
            $this->callSigned('GET',"/app/v3/gem-transactions/{$o->id}")->assertOk()
                ->assertHeader('Cache-Control','no-store, private')
                ->assertJsonPath('data.id',$o->id)->assertJsonPath('data.bot_id',$b->id)
                ->assertJsonPath('data.server_id',$b->server_id)->assertJsonPath('data.character_name','lezit1')
                ->assertJsonPath('data.delivery_method',$method)->assertJsonPath('data.gem_qty',20)
                ->assertJsonPath('data.status','completed')->assertJsonPath('data.delivery_phase',$phase)
                ->assertJsonPath('data.completed_at',null)->assertJsonPath('data.bot_gem_spent_qty',0)
                ->assertJsonPath('data.delivered_gem_qty',0)->assertJsonPath('data.tickets_used_qty',0);
            $this->assertSame($before,$o->fresh()->getAttributes());
            $this->assertSame($wallet,$o->user->fresh()->getAttributes());
        }
        $this->callSigned('GET','/app/v3/gem-transactions')->assertOk()->assertJsonCount(0,'data');
        $this->callSigned('GET','/app/v3/gem-transactions/999999999')->assertNotFound();
        $this->assertSame($beforeBot,$b->fresh()->getAttributes());
        $this->assertDatabaseCount('inventory_movements',0);
    }
    public function test_tool_cannot_overwrite_web_target_or_status(): void {
        $b=$this->bot();$this->lease($b);
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'lease','map_id'=>1,'coordinates'=>'0-0','status'=>false])->assertUnprocessable();
        $this->assertSame('84',$b->refresh()->map_id);$this->assertTrue($b->status);
    }
    public function test_gift_receipts_are_atomic_idempotent_and_keep_partial_progress(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$token=$this->claim($b,$o);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,
            'action'=>'phase','processing_token'=>$token,'character_id'=>1234,'phase'=>'gifting'])->assertOk();
        $p=$this->receipt($b,$token,1);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('data.delivered_gem_qty',10);
        $count=\App\Models\InventoryMovement::count();
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
        $this->assertSame(90,(int)$b->refresh()->gem_qty);$this->assertSame('processing',$o->refresh()->status);
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>2,'gem_qty'=>90,'gift_ticket_qty'=>9,'consign_item_qty'=>3])->assertConflict();
        $last=$this->receipt($b,$token,2,true);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$last)->assertOk()->assertJsonPath('data.status','completed');
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$last)->assertOk();
        $this->assertSame(80,(int)$b->refresh()->gem_qty);$this->assertSame(8,(int)$b->gift_ticket_qty);
        $this->callSigned('GET','/app/v3/gem-transactions?worker_id='.$this->worker)->assertJsonCount(0,'data');
        $last['bot_snapshot']['gem_qty']=0;$this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$last)->assertConflict();
    }
    public function test_other_owner_and_server_cannot_claim_and_v2_excludes_gifts(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);
        $this->getJson('/app/v2/gem-transactions',['X-APP-KEY'=>'legacy-test'])->assertOk()->assertJsonCount(0,'data');
        $this->putJson("/app/v2/gem-transactions/{$o->id}",['status'=>'processing','bot_id'=>$b->id],['X-APP-KEY'=>'legacy-test'])->assertConflict();
        $other=$this->bot();$this->lease($other);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$other->id,'action'=>'claim'])->assertUnprocessable();
        $this->claim($b,$o);
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>'00000000-0000-4000-8000-000000000002','action'=>'lease'])->assertOk();
    }
    public function test_public_ticket_order_reserves_stock_and_rejections_do_not_debit(): void {
        $b=$this->bot();$this->lease($b);
        \App\Models\GemPrice::create(['server_id'=>$b->server_id,'multiplier'=>2,'min_amount'=>10000,'status'=>true]);
        $user=User::factory()->create(['balance'=>100000]);
        \Laravel\Passport\Passport::actingAs($user,['profile:read','profile:write']);
        $p=['server_id'=>$b->server_id,'character_name'=>'buyer1','money_amount'=>10000,'delivery_method'=>'gift_ticket'];
        $this->postJson('/api/gem/orders',$p)->assertCreated();
        $this->assertSame(90000,(int)$user->fresh()->balance);
        $this->assertSame($b->id,(int)GemTransaction::first()->bot_id);
        $this->assertSame(80,\App\Services\TradingV3\GemStock::giftCapacity($b->fresh()));
        $p['character_name']='buyer2';$p['money_amount']=50000;
        $this->postJson('/api/gem/orders',$p)->assertConflict();
        $this->assertSame(90000,(int)$user->fresh()->balance);$this->assertDatabaseCount('gem_transactions',1);
        $p['money_amount']=9999;$this->postJson('/api/gem/orders',$p)->assertUnprocessable();
        $this->assertSame(90000,(int)$user->fresh()->balance);
        $this->getJson('/api/bots/overview')->assertJsonPath('data.gem_selling.0.gift_ticket_qty',10)->assertJsonPath('data.gem_selling.0.gift_available_gems',80);
        $p['money_amount']=11000;$this->postJson('/api/gem/orders',$p)->assertCreated()
            ->assertJsonPath('data.gem_qty',20)->assertJsonPath('data.charged_amount',10000)->assertJsonPath('data.unused_amount',1000);
        $this->assertSame(80000,(int)$user->fresh()->balance);
        $this->assertSame(60,\App\Services\TradingV3\GemStock::giftCapacity($b->fresh()));
    }
    public function test_v2_can_use_idle_bot_despite_old_lease_but_cannot_touch_an_unfinished_checkpoint(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b,'consignment');
        $headers=['X-APP-KEY'=>'legacy-test'];
        $this->getJson('/app/v2/gem/bots',$headers)->assertOk()->assertJsonCount(1,'data');
        $this->putJson('/app/v2/gem/bots/'.$b->id,['gem_qty'=>100],$headers)->assertOk();
        $token=$this->claim($b,$o);
        $this->assertNotEmpty($token);
        $this->getJson('/app/v2/gem/bots',$headers)->assertOk()->assertJsonCount(0,'data');
        $this->putJson('/app/v2/gem/bots/'.$b->id,['gem_qty'=>1],$headers)->assertConflict();
        $this->putJson('/app/v2/gem-transactions/'.$o->id,['status'=>'processing','bot_id'=>$b->id],$headers)->assertConflict();
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertOk();
        $this->assertTrue($o->fresh()->hasDeliveryCheckpoint());
        $this->getJson('/app/v2/gem/bots',$headers)->assertOk()->assertJsonCount(0,'data');
    }
    public function test_claim_terms_and_phase_receipts_cannot_be_overwritten(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$token=$this->claim($b,$o);
        $p=['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'phase','processing_token'=>$token,'step_id'=>(string)Str::uuid(),'character_id'=>1234,'phase'=>'gifting'];
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk();
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk();
        $p['phase']='buying';$this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertConflict();
        $o->update(['character_name'=>'another']);$p['step_id']=(string)Str::uuid();
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertConflict();
        $this->assertSame(100,(int)$b->fresh()->gem_qty);
    }
    public function test_consignment_requires_one_confirmed_equipment_trade_before_purchase(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b,'consignment');$token=$this->claim($b,$o);
        $base=['worker_id'=>$this->worker,'bot_id'=>$b->id,'processing_token'=>$token,'character_id'=>1234];
        $plan=['template_id'=>1,'signature'=>'1:87=0','quantity'=>1,'verified'=>false];
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",[...$base,'action'=>'phase','phase'=>'giving_item','delivery_data'=>['delivery_item'=>$plan]])->assertOk();
        $trade=[...$base,'action'=>'receipt','step_id'=>(string)Str::uuid(),'gem_spent_qty'=>0,'delivered_gem_qty'=>0,'tickets_used_qty'=>0,'completed'=>false,
            'evidence'=>['kind'=>'trade','confirmed'=>true],'bot_snapshot'=>['stock_version'=>1,'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>2]];
        $plan['verified']=true;$trade['delivery_data']=['delivery_item'=>$plan];
        $buy=[...$base,'action'=>'receipt','step_id'=>(string)Str::uuid(),'gem_spent_qty'=>20,'delivered_gem_qty'=>0,'tickets_used_qty'=>0,'completed'=>true,
            'evidence'=>['kind'=>'consignment','confirmed'=>true],'bot_snapshot'=>['stock_version'=>2,'gem_qty'=>80,'gift_ticket_qty'=>10,'consign_item_qty'=>3]];
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$buy)->assertUnprocessable();
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",[...$base,'action'=>'phase','phase'=>'waiting_consignment','delivery_data'=>['delivery_item'=>$plan]])->assertUnprocessable();
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$trade)->assertOk()->assertJsonPath('data.delivery_data.delivery_item.verified',true);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$trade)->assertOk();
        $this->assertSame(2,(int)$b->fresh()->consign_item_qty);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$buy)->assertOk()->assertJsonPath('data.status','completed');
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$buy)->assertOk();
        $this->assertSame(80,(int)$b->fresh()->gem_qty);$this->assertSame(3,(int)$b->consign_item_qty);
    }
    public function test_activity_is_an_event_and_does_not_change_database_bot(): void {
        $b=$this->bot();$this->lease($b);$before=$b->refresh()->getAttributes();
        $this->callSigned('POST',"/app/v3/gem/bots/{$b->id}/activity",['worker_id'=>$this->worker,'code'=>'travelling','map_id'=>1,'zone'=>2,'x'=>100,'y'=>200])->assertOk();

        $this->assertSame($before,$b->refresh()->getAttributes());
        Event::assertDispatched(TradingBotActivity::class,fn($e)=>$e->activity['code']==='travelling' && $e->activity['map_id']===1);
        $this->callSigned('POST',"/app/v3/gem/bots/{$b->id}/activity",['worker_id'=>$this->worker,'code'=>'paused','online_seconds'=>0])->assertOk();

        $this->assertSame($before,$b->refresh()->getAttributes());
        Event::assertDispatched(TradingBotActivity::class,fn($e)=>$e->activity['code']==='paused' && $e->activity['text']==='Đã tạm dừng Ngọc tại QLTK');
        $this->callSigned('POST',"/app/v3/gem/bots/{$b->id}/activity",['worker_id'=>$this->worker,'code'=>'reconciling','online_seconds'=>60])->assertOk();

        $this->assertSame($before,$b->refresh()->getAttributes());
        Event::assertDispatched(TradingBotActivity::class,fn($e)=>$e->activity['code']==='reconciling' && $e->activity['text']==='Đang đối chiếu tồn và giao dịch');
    }
    public function test_lost_done_response_can_be_acked_after_another_worker_leases_bot(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$token=$this->claim($b,$o);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,'action'=>'phase','processing_token'=>$token,'character_id'=>1234,'phase'=>'gifting'])->assertOk();
        $p=$this->receipt($b,$token,1,true);$p['gem_spent_qty']=20;$p['delivered_gem_qty']=20;$p['tickets_used_qty']=2;$p['bot_snapshot']['gem_qty']=80;$p['bot_snapshot']['gift_ticket_qty']=8;
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk();
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'release'])->assertOk();
        $next='00000000-0000-4000-8000-000000000002';
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$next,'action'=>'lease'])->assertOk();
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk()->assertJsonPath('ack_id',$p['step_id'])->assertJsonPath('data.status','completed');
        $this->assertSame(80,(int)$b->refresh()->gem_qty);
        $p['gem_spent_qty']=0;$this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertConflict();
    }
    public function test_gift_receipt_separates_external_inventory_changes_and_lost_ack(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",['bot_id'=>$b->id,'action'=>'phase','phase'=>'gifting','character_id'=>1234])->assertOk();
        $p=$this->receipt($b,$token,1);
        $p['bot_snapshot']=['stock_version'=>1,'gem_qty'=>190,'gift_ticket_qty'=>1009,'consign_item_qty'=>3];
        $path="/app/v3/gem-transactions/{$o->id}";
        $this->callSigned('PUT',$path,$p)->assertOk()->assertJsonPath('data.delivered_gem_qty',10);
        $this->assertSame(-10,(int)InventoryMovement::where('transaction_id',$o->id)->where('asset_type','gem')->sum('quantity_delta'));
        $this->assertSame(100,(int)InventoryMovement::where('movement_type','sync_correction')->where('asset_type','gem')->sum('quantity_delta'));
        $this->assertSame(1000,(int)InventoryMovement::where('movement_type','sync_correction')->where('asset_type','gem_ticket')->sum('quantity_delta'));
        $this->assertSame(0,InventoryMovement::where('movement_type','sync_correction')->whereNotNull('transaction_id')->count());
        $count=InventoryMovement::count();$this->callSigned('PUT',$path,$p)->assertOk()->assertJsonPath('ack_id',$p['step_id']);
        $this->assertDatabaseCount('inventory_movements',$count);$this->assertSame(190,(int)$b->refresh()->gem_qty);
        $p['bot_snapshot']['gem_qty']++;$this->callSigned('PUT',$path,$p)->assertConflict();
    }
    public function test_equipment_trade_and_consignment_accept_unrelated_gem_ticket_and_item_changes(): void {
        $b=$this->bot();$o=$this->order($b,'consignment');$this->claim($b,$o);$path="/app/v3/gem-transactions/{$o->id}";
        $base=['bot_id'=>$b->id,'character_id'=>1234];$plan=['template_id'=>1,'signature'=>'1:87=0','quantity'=>1,'verified'=>false];
        $this->callSigned('PUT',$path,[...$base,'action'=>'phase','phase'=>'giving_item','delivery_data'=>['delivery_item'=>$plan]])->assertOk();
        $plan['verified']=true;
        $trade=[...$base,'action'=>'receipt','step_id'=>(string)Str::uuid(),'gem_spent_qty'=>0,'delivered_gem_qty'=>0,'tickets_used_qty'=>0,'completed'=>false,
            'delivery_data'=>['delivery_item'=>$plan],'evidence'=>['kind'=>'trade','confirmed'=>true],
            'bot_snapshot'=>['stock_version'=>1,'gem_qty'=>150,'gift_ticket_qty'=>1010,'consign_item_qty'=>7]];
        $this->callSigned('PUT',$path,$trade)->assertOk()->assertJsonPath('data.delivery_data.delivery_item.verified',true);
        $buy=[...$base,'action'=>'receipt','step_id'=>(string)Str::uuid(),'gem_spent_qty'=>20,'delivered_gem_qty'=>19,'tickets_used_qty'=>0,'completed'=>true,
            'evidence'=>['kind'=>'consignment','confirmed'=>true],'bot_snapshot'=>['stock_version'=>2,'gem_qty'=>170,'gift_ticket_qty'=>1200,'consign_item_qty'=>10]];
        $this->callSigned('PUT',$path,$buy)->assertOk()->assertJsonPath('data.status','completed')->assertJsonPath('data.delivered_gem_qty',19);
        $this->assertSame(-20,(int)InventoryMovement::where('transaction_id',$o->id)->where('asset_type','gem')->sum('quantity_delta'));
        $this->assertSame(0,(int)InventoryMovement::where('transaction_id',$o->id)->where('asset_type','consign_item')->sum('quantity_delta'));
        $this->assertSame(90,(int)InventoryMovement::where('movement_type','sync_correction')->where('asset_type','gem')->sum('quantity_delta'));
        $this->assertSame(7,(int)InventoryMovement::where('movement_type','sync_correction')->where('asset_type','consign_item')->sum('quantity_delta'));
        $this->assertSame(170,(int)$b->refresh()->gem_qty);
    }
    public function test_stale_gift_receipt_settles_once_without_overwriting_newer_stock(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",['bot_id'=>$b->id,'action'=>'phase','phase'=>'gifting','character_id'=>1234])->assertOk();
        $p=$this->receipt($b,$token,1);$b->update(['stock_version'=>5,'gem_qty'=>500,'gift_ticket_qty'=>50]);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk();
        $this->assertSame(500,(int)$b->refresh()->gem_qty);$this->assertSame(5,(int)$b->stock_version);
        $movement=InventoryMovement::where('idempotency_key',"trading-v3:gem:{$o->id}:{$p['step_id']}:receipt")->firstOrFail();
        $this->assertSame(-10,$movement->quantity_delta);$this->assertSame('snapshot_already_current',$movement->meta['snapshot_resolution']);
        $count=InventoryMovement::count();$this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
        $this->assertSame(10,(int)$o->refresh()->delivered_gem_qty);
    }
    public function test_pending_gift_snapshot_requires_current_proof_and_keeps_stock_until_refresh(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);
        $this->callSigned('PUT',"/app/v3/gem-transactions/{$o->id}",['bot_id'=>$b->id,'action'=>'phase','phase'=>'gifting','character_id'=>1234])->assertOk();
        $p=$this->receipt($b,$token,2);$p['evidence']['intent']=['stock_snapshot_pending'=>true];$path="/app/v3/gem-transactions/{$o->id}";
        $this->callSigned('PUT',$path,$p)->assertUnprocessable();$this->assertSame(0,(int)$o->refresh()->delivered_gem_qty);
        $p['evidence']['intent']=['stock_snapshot_pending'=>true,'id'=>$p['step_id'],'order_id'=>$o->id,'kind'=>'gift','character_id'=>999,'amount'=>10,
            'server_success'=>['intent_id'=>$p['step_id'],'kind'=>'gift','outcome'=>'success','character_id'=>999,'recipient_name'=>'lezit1','amount'=>10,'source'=>'game_message','observed_at'=>now()->toIso8601String()]];
        $wrong=$p;$wrong['evidence']['intent']['server_success']['intent_id']=(string)Str::uuid();$this->callSigned('PUT',$path,$wrong)->assertUnprocessable();
        $this->callSigned('PUT',$path,$p)->assertOk()->assertJsonPath('data.delivered_gem_qty',10);
        $this->assertSame(100,(int)$b->refresh()->gem_qty);$this->assertSame(2,(int)$b->stock_version);
        $movement=InventoryMovement::where('idempotency_key',"trading-v3:gem:{$o->id}:{$p['step_id']}:receipt")->firstOrFail();
        $this->assertSame('snapshot_pending',$movement->meta['snapshot_resolution']);$this->assertSame(-10,$movement->quantity_delta);
        $this->assertTrue($movement->meta['snapshot_watermark_advanced']);
        // This HTTP upload was captured before the game effect but arrived after its receipt.
        $late=['action'=>'stock','stock_version'=>1,'gem_qty'=>111,'gift_ticket_qty'=>11,'consign_item_qty'=>3];
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",$late)->assertConflict();
        $this->assertSame(100,(int)$b->refresh()->gem_qty);$this->assertSame(2,(int)$b->stock_version);
        $count=InventoryMovement::count();$this->callSigned('PUT',$path,$p)->assertOk();$this->assertDatabaseCount('inventory_movements',$count);
        $stock=['action'=>'stock','stock_version'=>3,'gem_qty'=>190,'gift_ticket_qty'=>1009,'consign_item_qty'=>3];
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",$stock)->assertOk();
        $this->assertArrayNotHasKey('stock_refresh_pending_step_id',$o->refresh()->delivery_data);
        $stock['stock_version']=4;$this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",$stock)->assertConflict();
        $this->callSigned('PUT',$path,$p)->assertOk();$this->assertArrayNotHasKey('stock_refresh_pending_step_id',$o->refresh()->delivery_data);
    }
    public function test_delayed_equipment_receipt_does_not_block_another_claimed_customer_or_lose_frozen_plan(): void {
        $b=$this->bot();$equipment=$this->order($b,'consignment');$this->claim($b,$equipment);$equipmentPath="/app/v3/gem-transactions/{$equipment->id}";
        $plan=['template_id'=>1,'signature'=>'1:87=0','quantity'=>1,'verified'=>false];
        $this->callSigned('PUT',$equipmentPath,['bot_id'=>$b->id,'action'=>'phase','phase'=>'giving_item','character_id'=>1234,
            'delivery_data'=>['delivery_item'=>$plan]])->assertOk();
        $plan['verified']=true;
        $trade=['bot_id'=>$b->id,'character_id'=>1234,'action'=>'receipt','step_id'=>(string)Str::uuid(),'gem_spent_qty'=>0,'delivered_gem_qty'=>0,'tickets_used_qty'=>0,'completed'=>false,
            'delivery_data'=>['delivery_item'=>$plan],'evidence'=>['kind'=>'trade','confirmed'=>true],
            'bot_snapshot'=>['stock_version'=>1,'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>2]];
        // Its game trade is confirmed locally, but its frozen HTTP receipt is still queued.
        $gift=$this->order($b);$token=$this->claim($b,$gift);$giftPath="/app/v3/gem-transactions/{$gift->id}";
        $this->callSigned('PUT',$giftPath,['bot_id'=>$b->id,'action'=>'phase','phase'=>'gifting','character_id'=>5678])->assertOk();
        $giftReceipt=$this->receipt($b,$token,2,true);$giftReceipt['character_id']=5678;$giftReceipt['gem_spent_qty']=20;$giftReceipt['delivered_gem_qty']=20;$giftReceipt['tickets_used_qty']=2;
        $giftReceipt['bot_snapshot']=['stock_version'=>2,'gem_qty'=>80,'gift_ticket_qty'=>8,'consign_item_qty'=>2];
        $this->callSigned('PUT',$giftPath,$giftReceipt)->assertOk()->assertJsonPath('data.status','completed');
        $buy=['bot_id'=>$b->id,'character_id'=>1234,'action'=>'receipt','step_id'=>(string)Str::uuid(),'gem_spent_qty'=>20,'delivered_gem_qty'=>19,'tickets_used_qty'=>0,'completed'=>true,
            'evidence'=>['kind'=>'consignment','confirmed'=>true],'bot_snapshot'=>['stock_version'=>4,'gem_qty'=>60,'gift_ticket_qty'=>8,'consign_item_qty'=>3]];
        $this->callSigned('PUT',$equipmentPath,$buy)->assertUnprocessable();
        $stock=['action'=>'stock','stock_version'=>3,'gem_qty'=>80,'gift_ticket_qty'=>8,'consign_item_qty'=>2];
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",$stock)->assertConflict();
        $wrong=$trade;$wrong['delivery_data']['delivery_item']['signature']='different-item';$this->callSigned('PUT',$equipmentPath,$wrong)->assertUnprocessable();
        $this->callSigned('PUT',$equipmentPath,$trade)->assertOk()->assertJsonPath('data.delivery_data.delivery_item.verified',true);
        $this->assertSame(80,(int)$b->refresh()->gem_qty);$this->assertSame(2,(int)$b->stock_version);
        $this->callSigned('PUT',"/app/v3/gem/bots/{$b->id}",$stock)->assertOk();
        $this->callSigned('PUT',$equipmentPath,$buy)->assertOk()->assertJsonPath('data.status','completed');
        $count=InventoryMovement::count();$this->callSigned('PUT',$equipmentPath,$trade)->assertOk();$this->callSigned('PUT',$giftPath,$giftReceipt)->assertOk();
        $this->assertDatabaseCount('inventory_movements',$count);$this->assertSame(60,(int)$b->refresh()->gem_qty);
        $this->assertSame(-40,(int)InventoryMovement::where('movement_type','sale')->where('asset_type','gem')->sum('quantity_delta'));
        $this->assertSame(0,(int)InventoryMovement::where('movement_type','sync_correction')->where('asset_type','gem')->sum('quantity_delta'));
        $this->assertSame('completed',$equipment->refresh()->status);$this->assertSame('completed',$gift->refresh()->status);
    }
    public function test_admin_can_resolve_a_claimed_v3_order_without_rewriting_bot_inventory(): void {
        $b=$this->bot();$this->lease($b);$o=$this->order($b);$this->claim($b,$o);
        \Spatie\Permission\Models\Role::findOrCreate('super-admin','web');
        $admin=User::factory()->create();$admin->assignRole('super-admin');$this->actingAs($admin);
        $this->post('/admin/gem-orders/'.$o->id.'/refund',['refund_reason'=>'test'])->assertSessionHasNoErrors();
        $this->patch('/admin/gem-orders/'.$o->id.'/status',['action'=>'complete'])->assertSessionHasErrors('status');
        $this->assertSame('refunded',$o->fresh()->status);$this->assertNotNull($o->fresh()->refunded_at);$this->assertSame(100,(int)$b->fresh()->gem_qty);
        $resource=(new \App\Http\Resources\GemOrder\GemOrderResource($o->fresh()))->resolve();
        $this->assertFalse($resource['can_refund']);$this->assertFalse($resource['can_complete']);$this->assertFalse($resource['can_cancel']);
        $other=$this->order($b);$this->claim($b,$other);
        $this->patch('/admin/gem-orders/'.$other->id.'/status',['action'=>'complete'])->assertSessionHasNoErrors();
        $this->assertSame('manual_completed',$other->fresh()->delivery_phase);$this->assertSame(100,(int)$b->fresh()->gem_qty);
    }
}

