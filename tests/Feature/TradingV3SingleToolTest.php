<?php
namespace Tests\Feature;
use App\Models\{GemBot,GemTransaction,InventoryMovement,Server,Transaction,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,Event};
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingV3SingleToolTest extends TestCase {
    use RefreshDatabase;
    private string $worker='00000000-0000-4000-8000-000000000061';
    private string $secret='single-tool-test-secret-at-least-32-chars';
    protected function setUp(): void {
        parent::setUp();Event::fake();
        config()->set('trading_v3',require base_path('config/trading_v3.php'));
        config(['trading_v3.enabled'=>true,'trading_v3.hmac_secret'=>$this->secret]);
    }
    private function signed(string $method,string $path,array $data=[]) {
        $time=(string)time();$nonce=bin2hex(random_bytes(16));$id='nro-trading-v3';$body=$method==='GET'?'':json_encode($data);
        $headers=['X-Tool-Id'=>$id,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,'X-Tool-Signature'=>hash_hmac('sha256',implode("\n",[$method,$path,$id,$time,$nonce,hash('sha256',$body)]),$this->secret)];
        return $method==='GET'?$this->get($path,[...$headers,'Accept'=>'application/json']):$this->json($method,$path,$data,$headers);
    }
    private function bot(): GemBot {
        $server=Server::create(['name'=>'synthetic','status'=>true]);
        return GemBot::create(['name'=>'synthetic','account_name'=>'test','account_password'=>'fake','server_id'=>$server->id,
            'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>3,'status'=>true,'map_id'=>'84','area_number'=>'1','coordinates'=>'652-336']);
    }
    private function order(GemBot $b,string $method='gift_ticket',array $extra=[]): GemTransaction {
        $order=GemTransaction::create(array_replace(['server_id'=>$b->server_id,'user_id'=>User::factory()->create(['balance'=>50000])->id,
            'character_name'=>'recipient','gem_qty'=>10,'amount_vnd'=>10000,'price_at_transaction'=>10,'status'=>'pending','delivery_method'=>$method],$extra));
        if(isset($extra['created_at']))$order->forceFill(['created_at'=>$extra['created_at']])->save();
        return $order;
    }
    private function update(GemBot $b,GemTransaction $o,array $data) {
        return $this->signed('PUT',"/app/v3/gem-transactions/{$o->id}",['worker_id'=>$this->worker,'bot_id'=>$b->id,...$data]);
    }
    private function claim(GemBot $b,GemTransaction $o): string {
        $this->update($b,$o,['action'=>'claim'])->assertOk()->assertJsonPath('data.has_delivery_checkpoint',true);return (string)Str::uuid(); // Legacy wire field only.
    }
    private function gift(string $token): array {
        return ['action'=>'receipt','processing_token'=>$token,'step_id'=>(string)Str::uuid(),'gem_spent_qty'=>10,'delivered_gem_qty'=>10,
            'tickets_used_qty'=>1,'completed'=>true,'bot_snapshot'=>['stock_version'=>1,'gem_qty'=>90,'gift_ticket_qty'=>9,'consign_item_qty'=>3],
            'evidence'=>['kind'=>'gift','confirmed'=>true,'recipient_name'=>'recipient','gift_gem_qty'=>10,'gift_ticket_qty'=>1]];
    }
    public function test_lists_and_terminal_details_survive_changed_worker_and_legacy_versions(): void {
        $b=$this->bot();$legacy=$this->order($b,'consignment',['status'=>'processing','bot_id'=>$b->id,'item'=>'legacy item']);
        $owned=$this->order($b,'gift_ticket',['status'=>'processing','bot_id'=>$b->id,'delivery_data'=>['claim'=>['marker'=>'checkpoint']]]);
        $done=$this->order($b,'gift_ticket',['status'=>'completed']);
        $this->signed('GET','/app/v3/gem-transactions')->assertOk()->assertJsonCount(2,'data')->assertJsonPath('data.0.item','legacy item');
        $this->signed('GET',"/app/v3/gem-transactions/{$done->id}")->assertOk()->assertJsonPath('data.status','completed');
        $before=$legacy->fresh()->getAttributes();
        $this->signed('POST','/app/v3/heartbeat',['worker_id'=>$this->worker,'single_tool_mode'=>true,'gem_bot_ids'=>[$b->id]])
            ->assertOk()->assertJsonPath('purpose','compatibility')->assertJsonPath('gem_bots.0.granted',true);
        $this->assertSame($before,$legacy->fresh()->getAttributes());
        $this->update($b,$legacy,['action'=>'claim'])->assertOk()->assertJsonPath('data.delivery_phase','waiting_consignment')
            ->assertJsonPath('data.delivery_data.legacy_resume.no_redelivery',true);
        $this->signed('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,
            'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>3])->assertConflict();
        // The unrelated active gift still prevents overwriting stock while its result is unresolved.
        $this->assertSame(0,(int)$b->fresh()->stock_version);
    }
    public function test_legacy_consignment_adopts_observed_listing_without_redelivery_or_synthetic_stock_debit(): void {
        $b=$this->bot();$o=$this->order($b,'consignment',['status'=>'processing','bot_id'=>$b->id,'item'=>'old opaque item']);
        $this->update($b,$o,['action'=>'start'])->assertOk()->assertJsonPath('data.delivery_phase','waiting_consignment');
        $adopted=$o->fresh()->delivery_data;
        $this->update($b,$o,['action'=>'start'])->assertOk();
        $this->assertSame($adopted,$o->fresh()->delivery_data);
        $this->assertSame('old opaque item',$adopted['legacy_resume']['original_item']);
        $this->assertSame(3,\App\Services\TradingV3\GemStock::available($b)['items']);
        $this->assertSame(0,InventoryMovement::count());
        $this->update($b,$o,['action'=>'release_unstarted','step_id'=>(string)Str::uuid(),'evidence'=>['no_effect'=>true]])->assertConflict();
        $plan=['template_id'=>12,'signature'=>'12:87=0','quantity'=>1,'verified'=>true,'options'=>[]];
        $phase=['action'=>'phase','phase'=>'waiting_consignment','step_id'=>(string)Str::uuid(),
            'delivery_data'=>['listing_id'=>765,'delivery_item'=>$plan],
            'evidence'=>['kind'=>'legacy_listing','confirmed'=>true,'recipient_name'=>'recipient','listing_id'=>765,'gem_qty'=>10,'quantity'=>1,'buy_type'=>1]];
        $wrong=$phase;$wrong['evidence']['recipient_name']='another';$this->update($b,$o,$wrong)->assertUnprocessable();
        $this->update($b,$o,$phase)->assertOk();$this->update($b,$o,$phase)->assertOk();
        $this->assertNull($o->fresh()->character_id);
        $this->assertSame(100,(int)$b->fresh()->gem_qty);
        $this->assertSame(3,(int)$b->fresh()->consign_item_qty);
        $this->assertSame(0,InventoryMovement::where('asset_type','gem')->count());
        $different=$phase;$different['step_id']=(string)Str::uuid();$different['delivery_data']['delivery_item']['signature']='different';
        $this->update($b,$o,$different)->assertConflict();
        $receipt=['action'=>'receipt','step_id'=>(string)Str::uuid(),'gem_spent_qty'=>10,'delivered_gem_qty'=>10,
            'tickets_used_qty'=>0,'completed'=>true,'bot_snapshot'=>['stock_version'=>1,'gem_qty'=>90,'gift_ticket_qty'=>10,'consign_item_qty'=>4],
            'evidence'=>['kind'=>'consignment','confirmed'=>true,'recipient_name'=>'recipient','listing_id'=>765]];
        $wrong=$receipt;$wrong['evidence']['listing_id']=766;$this->update($b,$o,$wrong)->assertUnprocessable();
        $this->update($b,$o,$receipt)->assertOk()->assertJsonPath('data.status','completed');
        $this->update($b,$o,$receipt)->assertOk();
        $this->assertSame(1,InventoryMovement::where('asset_type','gem')->count());
        $this->assertSame(4,(int)$b->fresh()->consign_item_qty);
    }
    public function test_normal_consignment_cannot_fake_a_legacy_listing_or_skip_handover(): void {
        $b=$this->bot();$o=$this->order($b,'consignment');$this->claim($b,$o);
        $this->update($b,$o,['action'=>'phase','phase'=>'waiting_consignment','step_id'=>(string)Str::uuid(),
            'delivery_data'=>['listing_id'=>765,'delivery_item'=>['template_id'=>12,'signature'=>'12:87=0','quantity'=>1,'verified'=>true]],
            'evidence'=>['kind'=>'legacy_listing','confirmed'=>true,'recipient_name'=>'recipient','listing_id'=>765,'gem_qty'=>10,'quantity'=>1,'buy_type'=>1]])->assertUnprocessable();
        $gift=$this->order($b,'gift_ticket',['status'=>'processing','bot_id'=>$b->id]);
        $this->update($b,$gift,['action'=>'start'])->assertConflict();
        $this->assertEmpty($gift->fresh()->delivery_data['claim']??null);
    }
    public function test_unadopted_v2_processing_reserves_gems_but_does_not_hold_equipment_for_a_new_customer(): void {
        $b=$this->bot();$b->update(['consign_item_qty'=>1]);
        $legacy=$this->order($b,'consignment',['status'=>'processing','bot_id'=>$b->id,'item'=>'already handed by v2']);
        $available=\App\Services\TradingV3\GemStock::available($b);
        $this->assertSame(90,$available['gems']);$this->assertSame(1,$available['items']);
        $next=$this->order($b,'consignment');
        $this->update($b,$next,['action'=>'start'])->assertOk()->assertJsonPath('data.delivery_phase','waiting_customer');
        $this->assertEmpty($legacy->fresh()->delivery_data['claim']??null);
        $this->assertSame('already handed by v2',$legacy->fresh()->item);
        $this->assertSame(0,\App\Services\TradingV3\GemStock::available($b)['items']);
        $this->assertSame(1,(int)$b->fresh()->consign_item_qty);
        $this->assertSame(0,InventoryMovement::count());
        $next->refresh()->update(['status'=>'pending','delivery_data'=>null]);
        $this->assertSame(0,\App\Services\TradingV3\GemStock::available($b)['items']);
    }
    public function test_gift_by_frozen_name_needs_no_invite_or_character_id_and_receipts_remain_idempotent(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);$p=$this->gift($token);
        $wrong=$p;$wrong['evidence']['recipient_name']='another';$this->update($b,$o,$wrong)->assertUnprocessable();
        $this->assertSame(100,(int)$b->fresh()->gem_qty);
        $this->update($b,$o,$p)->assertOk()->assertJsonPath('data.status','completed');
        $this->update($b,$o,$p)->assertOk();
        $this->assertNull($o->fresh()->character_id);
        $this->assertSame(90,(int)$b->fresh()->gem_qty);
        $this->assertSame(1,InventoryMovement::where('asset_type','gem')->count());
        $p['gem_spent_qty']=20;$this->update($b,$o,$p)->assertConflict();
    }
    public function test_failed_pre_delivery_can_release_then_refund_pending_once_using_same_cron_key(): void {
        $b=$this->bot();$o=$this->order($b,'consignment',['created_at'=>now()->subMinutes(11)]);$token=$this->claim($b,$o);
        $release=['action'=>'release_unstarted','processing_token'=>$token,'step_id'=>(string)Str::uuid(),'evidence'=>['no_effect'=>true]];
        $this->update($b,$o,$release)->assertOk()->assertJsonPath('data.status','pending');
        $this->update($b,$o,$release)->assertOk();
        $cancel=['action'=>'timeout','step_id'=>(string)Str::uuid(),'timeout_minutes'=>10,'refund_grace_minutes'=>0];
        $this->update($b,$o,$cancel)->assertOk()->assertJsonPath('data.status','refunded')->assertJsonPath('ack_id',$cancel['step_id']);
        $this->update($b,$o,$cancel)->assertOk();$this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(60000,(int)$o->user->fresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key',"gem-order-timeout-refund:{$o->id}")->count());
        $this->assertSame(100,(int)$b->fresh()->gem_qty);
    }
    public function test_offline_gift_no_effect_can_return_pending_but_successful_gift_cannot(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);
        $this->update($b,$o,['action'=>'phase','processing_token'=>$token,'phase'=>'gifting'])->assertOk();
        $this->update($b,$o,['action'=>'release_unstarted','processing_token'=>$token,'step_id'=>(string)Str::uuid(),'evidence'=>['no_effect'=>true]])
            ->assertOk()->assertJsonPath('data.status','pending');
        $token=$this->claim($b,$o);$this->update($b,$o,$this->gift($token))->assertOk();
        $this->update($b,$o,['action'=>'release_unstarted','processing_token'=>$token,'step_id'=>(string)Str::uuid(),'evidence'=>['no_effect'=>true]])->assertConflict();
    }
    public function test_processing_is_never_timeout_refunded_and_pending_deadline_is_checked_by_backend(): void {
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['created_at'=>now()->subHour()]);$this->claim($b,$o);
        $this->update($b,$o,['action'=>'timeout','step_id'=>(string)Str::uuid(),'timeout_minutes'=>10,'refund_grace_minutes'=>0])->assertConflict();
        $fresh=$this->order($b);$this->update($b,$fresh,['action'=>'timeout','step_id'=>(string)Str::uuid(),'timeout_minutes'=>10,'refund_grace_minutes'=>0])->assertConflict();
        $this->assertSame(50000,(int)$o->user->fresh()->balance);
    }
    public function test_unassigned_pending_timeout_does_not_assign_bot_and_tool_grace_is_shared_with_cron(): void {
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['created_at'=>now()->subMinutes(11)]);
        $this->update($b,$o,['action'=>'timeout','step_id'=>(string)Str::uuid(),'timeout_minutes'=>10,'refund_grace_minutes'=>2])
            ->assertOk()->assertJsonPath('data.status','cancelled')->assertJsonPath('data.bot_id',null);
        $refund=['action'=>'refund','step_id'=>(string)Str::uuid()];$this->update($b,$o,$refund)->assertConflict();
        $this->travel(2)->minutes();$this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->update($b,$o,$refund)->assertOk()->assertJsonPath('data.status','refunded');
        $this->assertSame(60000,(int)$o->user->fresh()->balance);
    }
    public function test_terminal_phase_is_acknowledged_read_only_after_cached_claim(): void {
        $b=$this->bot();$o=$this->order($b);$token=$this->claim($b,$o);$this->update($b,$o,$this->gift($token))->assertOk();
        $before=$o->fresh()->getAttributes();$count=InventoryMovement::count();
        $this->update($b,$o,['action'=>'claim'])->assertOk()->assertJsonPath('data.status','completed');
        $this->update($b,$o,['action'=>'phase','processing_token'=>$token,'phase'=>'waiting_customer','step_id'=>(string)Str::uuid()])->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame($before,$o->fresh()->getAttributes());$this->assertSame($count,InventoryMovement::count());
    }
    public function test_confirmed_equipment_cannot_be_released_refunded_or_replaced_and_waiting_stock_sync_is_safe(): void {
        $b=$this->bot();$o=$this->order($b,'consignment');$token=$this->claim($b,$o);
        $plan=['template_id'=>1,'signature'=>'1:87=0','quantity'=>1,'verified'=>false];
        $this->update($b,$o,['action'=>'phase','processing_token'=>$token,'character_id'=>1234,'phase'=>'giving_item','delivery_data'=>['delivery_item'=>$plan]])->assertOk();
        $verified=$plan;$verified['verified']=true;
        $trade=['action'=>'receipt','processing_token'=>$token,'character_id'=>1234,'step_id'=>(string)Str::uuid(),'gem_spent_qty'=>0,
            'delivered_gem_qty'=>0,'tickets_used_qty'=>0,'completed'=>false,'delivery_data'=>['delivery_item'=>$verified],
            'evidence'=>['kind'=>'trade','confirmed'=>true],'bot_snapshot'=>['stock_version'=>1,'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>2]];
        $this->update($b,$o,$trade)->assertOk();
        $this->update($b,$o,['action'=>'release_unstarted','processing_token'=>$token,'step_id'=>(string)Str::uuid(),'evidence'=>['no_effect'=>true]])->assertConflict();
        $this->update($b,$o,['action'=>'timeout','step_id'=>(string)Str::uuid(),'timeout_minutes'=>1,'refund_grace_minutes'=>0])->assertConflict();
        $this->update($b,$o,['action'=>'phase','processing_token'=>$token,'phase'=>'giving_item','delivery_data'=>['delivery_item'=>$plan]])->assertConflict();
        $this->signed('PUT',"/app/v3/gem/bots/{$b->id}",['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>2,
            'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>2])->assertOk();
        $this->assertTrue($o->fresh()->delivery_data['delivery_item']['verified']);
        $this->assertSame(50000,(int)$o->user->fresh()->balance);
    }
    public function test_partial_name_gifts_only_finish_remaining_quantity_and_old_receipt_can_still_be_acked(): void {
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['gem_qty'=>20]);$token=$this->claim($b,$o);
        $first=$this->gift($token);$first['completed']=false;
        $this->update($b,$o,$first)->assertOk()->assertJsonPath('data.bot_gem_spent_qty',10);
        $last=$this->gift($token);$last['bot_snapshot']=['stock_version'=>2,'gem_qty'=>80,'gift_ticket_qty'=>8,'consign_item_qty'=>3];
        $this->update($b,$o,$last)->assertOk()->assertJsonPath('data.status','completed');
        $this->update($b,$o,$first)->assertOk();
        $this->assertSame(80,(int)$b->fresh()->gem_qty);$this->assertSame(2,(int)$o->fresh()->tickets_used_qty);
        $this->assertSame(2,InventoryMovement::where('asset_type','gem')->count());
    }
    public function test_cached_tool_policy_cannot_override_cron_defaults_and_v2_fallback_still_refunds(): void {
        config(['trading.gem_order_pending_timeout_minutes'=>10,'trading.gem_order_refund_grace_minutes'=>5]);
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['created_at'=>now()->subMinutes(11)]);
        // Simulate a policy left in the cache by an earlier v3 binary. Web cron stays independent.
        Cache::forever('trading-v3:gem-timeout-policy',['enabled'=>true,'timeout_minutes'=>20,'refund_grace_minutes'=>0]);
        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame('cancelled',$o->fresh()->status);$this->assertSame(5,$o->fresh()->delivery_data['timeout_policy']['refund_grace_minutes']);
        $this->assertSame(50000,(int)$o->user->fresh()->balance);
        $this->travel(5)->minutes();$this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame('refunded',$o->fresh()->status);$this->assertSame(60000,(int)$o->user->fresh()->balance);
    }
    public function test_tool_refunds_pending_immediately_at_exact_second_deadline_with_frozen_ack_and_no_grace(): void {
        $this->freezeTime();
        config(['trading.gem_order_pending_timeout_minutes'=>11,'trading.gem_order_refund_grace_minutes'=>5]);
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['created_at'=>now()->subSeconds(59),'amount_vnd'=>11429]);
        $p=['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>60];
        $this->update($b,$o,$p)->assertConflict();
        $this->assertNull($o->fresh()->cancel_requested_at);$this->assertSame(50000,(int)$o->user->fresh()->balance);
        $this->travel(1)->seconds();
        $this->update($b,$o,$p)->assertOk()->assertJsonPath('data.status','refunded')->assertJsonPath('ack_id',$p['step_id'])->assertJsonPath('data.bot_id',null);
        $after=$o->fresh()->getAttributes();
        $this->update($b,$o,$p)->assertOk();
        $this->assertSame($after,$o->fresh()->getAttributes());
        $this->assertSame(61429,(int)$o->user->fresh()->balance);
        $this->assertSame(0,$o->fresh()->delivery_data['timeout_policy']['refund_grace_minutes']);
        $this->assertSame(60,$o->fresh()->delivery_data['timeout_policy']['timeout_seconds']);
        $this->assertDatabaseHas('transactions',['idempotency_key'=>"gem-order-timeout-refund:{$o->id}",'amount'=>11429]);
        $mutated=$p;$mutated['timeout_seconds']=61;$this->update($b,$o,$mutated)->assertConflict();
        $this->travel(30)->minutes();$this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(61429,(int)$o->user->fresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key',"gem-order-timeout-refund:{$o->id}")->count());
        $this->assertSame(100,(int)$b->fresh()->gem_qty);
    }
    public function test_claim_and_pending_refund_race_serializations_do_not_refund_processing_or_restart_refunded_orders(): void {
        $b=$this->bot();$claimFirst=$this->order($b,'gift_ticket',['created_at'=>now()->subMinutes(11)]);
        $token=$this->claim($b,$claimFirst);
        $this->update($b,$claimFirst,['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>600])->assertConflict();
        $this->assertSame('processing',$claimFirst->fresh()->status);$this->assertSame(50000,(int)$claimFirst->user->fresh()->balance);
        $refundFirst=$this->order($b,'gift_ticket',['created_at'=>now()->subMinutes(11)]);
        $this->update($b,$refundFirst,['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>600])->assertOk();
        $this->update($b,$refundFirst,['action'=>'claim'])->assertOk()->assertJsonPath('data.status','refunded')->assertJsonPath('data.has_delivery_checkpoint',false);
        $this->update($b,$refundFirst,$this->gift($token))->assertConflict();
        $this->assertSame(60000,(int)$refundFirst->user->fresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key',"gem-order-timeout-refund:{$refundFirst->id}")->count());
    }
    public function test_new_pending_refund_does_not_bypass_cron_cancelled_grace_and_legacy_outbox_can_finish(): void {
        config(['trading.gem_order_pending_timeout_minutes'=>10,'trading.gem_order_refund_grace_minutes'=>5]);
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['created_at'=>now()->subMinutes(11)]);
        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->update($b,$o,['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>600])->assertConflict();
        $this->assertSame(50000,(int)$o->user->fresh()->balance);
        $this->travel(5)->minutes();$this->artisan('gold:cancel-stale')->assertSuccessful();
        $p=['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>600];
        $before=$o->fresh()->getAttributes();$this->update($b,$o,$p)->assertOk()->assertJsonPath('data.status','refunded');
        $this->assertSame($before,$o->fresh()->getAttributes());
        $this->assertSame(60000,(int)$o->user->fresh()->balance);
        $legacy=['action'=>'refund','step_id'=>(string)Str::uuid()];
        $this->update($b,$o,$legacy)->assertOk()->assertJsonPath('data.status','refunded');
        $this->assertSame(1,Transaction::where('idempotency_key',"gem-order-timeout-refund:{$o->id}")->count());
    }
    public function test_completed_order_is_read_only_and_stale_pending_with_verified_delivery_is_not_refundable(): void {
        $b=$this->bot();$done=$this->order($b,'gift_ticket',['created_at'=>now()->subHour()]);$token=$this->claim($b,$done);
        $this->update($b,$done,$this->gift($token))->assertOk();$before=$done->fresh()->getAttributes();
        $this->update($b,$done,['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>60])->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame($before,$done->fresh()->getAttributes());$this->assertSame(50000,(int)$done->user->fresh()->balance);
        $stale=$this->order($b,'consignment',['created_at'=>now()->subHour(),'delivery_data'=>['delivery_item'=>['verified'=>true]]]);
        $this->update($b,$stale,['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>60])->assertConflict();
        $this->assertSame('pending',$stale->fresh()->status);$this->assertNull($stale->fresh()->cancel_requested_at);
    }
    public function test_refund_credit_failure_rolls_back_cancellation_and_frozen_ack(): void {
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['created_at'=>now()->subHour()]);
        $o->user->forceFill(['balance'=>\App\Services\TransactionService::MAX_BALANCE])->save();
        $p=['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>600];
        $this->update($b,$o,$p)->assertUnprocessable();
        $this->assertSame('pending',$o->fresh()->status);$this->assertNull($o->fresh()->cancel_requested_at);$this->assertNull($o->fresh()->refunded_at);
        $this->assertSame(0,Transaction::where('idempotency_key',"gem-order-timeout-refund:{$o->id}")->count());
        $this->assertSame(0,InventoryMovement::where('idempotency_key',"trading-v3:gem:{$o->id}:{$p['step_id']}:refund_pending")->count());
        $o->user->forceFill(['balance'=>50000])->save();$this->update($b,$o,$p)->assertOk()->assertJsonPath('data.status','refunded');
        $this->assertSame(60000,(int)$o->user->fresh()->balance);
    }
    public function test_refund_pending_requires_valid_seconds_and_uuid_before_mutating_order(): void {
        $b=$this->bot();$o=$this->order($b,'gift_ticket',['created_at'=>now()->subHour()]);
        foreach([0,86401,null] as $seconds)$this->update($b,$o,['action'=>'refund_pending','step_id'=>(string)Str::uuid(),'timeout_seconds'=>$seconds])->assertUnprocessable();
        $this->update($b,$o,['action'=>'refund_pending','timeout_seconds'=>600])->assertUnprocessable();
        $this->assertSame('pending',$o->fresh()->status);$this->assertSame(50000,(int)$o->user->fresh()->balance);
    }
}
