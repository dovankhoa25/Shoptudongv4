<?php
namespace Tests\Feature;
use App\Models\{NroAccount,User};
use App\Services\{NroShopService,NroSnapshotService,NroReceivingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NroBatchDeliveryTest extends TestCase
{
    use RefreshDatabase;
    private User $seller;private User $buyer;private NroAccount $account;private int $listing;private string $instance;private array $jobs=[];
    protected function setUp():void {
        parent::setUp();$this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Role::findOrCreate('ctv','web');$this->seller=User::factory()->create(['balance'=>0]);$this->seller->assignRole('ctv');
        $this->seller->givePermissionTo(['nro-accounts.manage','item-listings.manage','item-listings.view']);
        $this->buyer=User::factory()->create(['balance'=>100000]);$this->instance=(string)Str::uuid();
        DB::table('servers')->insert(['id'=>10,'name'=>'sv2','name_view'=>'Vũ Trụ 2','status'=>true]);
        DB::table('server_game_login')->insert(['id'=>37,'name'=>'Test','ip'=>'127.0.0.1','port'=>'14445']);
        DB::table('nro_worker_keys')->insert(['name'=>'worker','token_hash'=>hash('sha256','test-batch-0123456789012345678901234567890123456789'),'accepts_delivery'=>true,'last_used_at'=>now()]);
        $this->account=NroAccount::create(['user_id'=>$this->seller->id,'account_name'=>'warehouse','game_password'=>'secret','server'=>'vt2','server_index'=>1,'server_id'=>10,'server_game_id'=>37,'usage_type'=>'warehouse','status'=>'active']);
        app(NroSnapshotService::class)->ingest($this->account,$this->payload(100));
        $inventory=DB::table('nro_inventory_items')->where('account_id',$this->account->id)->value('id');
        $this->listing=$this->actingAs($this->seller,'web')->postJson('/admin/nro-shop/accounts/'.$this->account->id.'/listings',[
            'price'=>1000,'costPrice'=>400,'stockMode'=>'auto','items'=>[['id'=>$inventory,'quantity'=>1]]])->assertOk()->json('id');
    }
    private function payload(int $quantity):array {
        return ['schemaVersion'=>1,'catalogVersion'=>'17','completeness'=>['bag'=>true,'chest'=>true,'equipped'=>true],
            'snapshot'=>['capturedAt'=>now()->toIso8601String(),'character'=>['id'=>1,'name'=>'bot','gender'=>0],
                'bag'=>$quantity?[['slot'=>0,'templateId'=>223,'quantity'=>$quantity,'options'=>[]]]:[],'chest'=>[],'equipped'=>[],'collectionChest'=>[]]];
    }
    private function order(int $quantity=10,?User $buyer=null,string $mode='manual'):int {
        $buyer??=$this->buyer;$order=app(NroShopService::class)->purchase($buyer,$this->listing,'',10,(string)Str::uuid(),$quantity,1000);
        app(NroReceivingService::class)->start($buyer,$order,['mode'=>$mode,'recipientName'=>'customer','username'=>'receiver','password'=>'not-real','requestKey'=>(string)Str::uuid()]);return $order;
    }
    private function claim():array {
        $job=$this->withToken('test-batch-0123456789012345678901234567890123456789')->postJson('/app/nro-worker/claim',['protocolVersion'=>5,'workerInstance'=>$this->instance,'types'=>['delivery']])->assertOk()->json('data');
        $this->assertNotNull($job);$this->jobs[]=$job;
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/ready',['leaseToken'=>$job['leaseToken'],'characterId'=>1,'name'=>'bot','mapId'=>5,'zone'=>20,'recipientName'=>'customer','recipientCharacterId'=>99])->assertOk();return $job;
    }
    private function plan(array $jobs):array {
        return ['workerInstance'=>$this->instance,'batchKey'=>(string)Str::uuid(),'recipientName'=>'customer','characterId'=>99,
            'members'=>array_map(fn($j)=>['jobId'=>$j['id'],'leaseToken'=>$j['leaseToken'],'roundKey'=>(string)Str::uuid(),
                'checkpoint'=>array_map(fn($i)=>['id'=>$i['id'],'before'=>100,'offered'=>$i['quantity']-$i['delivered'],'delivered'=>$i['delivered']],$j['order']['items'])],$jobs)];
    }
    private function receiptPayload(array $plan):array {
        return ['batchKey'=>$plan['batchKey'],'outcome'=>'success','members'=>array_map(fn($m)=>['jobId'=>$m['jobId'],'leaseToken'=>$m['leaseToken'],'roundKey'=>$m['roundKey'],
            'items'=>array_map(fn($i)=>['id'=>$i['id'],'delivered'=>$i['delivered']+$i['offered']],$m['checkpoint'])],$plan['members'])];
    }
    private function url(string $action):string{return '/app/nro-worker/accounts/'.$this->account->id.'/batch-'.$action;}
    public function test_grouped_delivery_settles_each_order_once_and_leaves_other_stock_buyable():void {
        $a=$this->order(10);$b=$this->order(20);$plan=$this->plan([$this->claim(),$this->claim()]);
        $this->postJson($this->url('begin'),$plan)->assertOk();$this->postJson($this->url('begin'),$plan)->assertOk();
        $receipt=$this->receiptPayload($plan);$this->postJson($this->url('receipt'),$receipt)->assertOk();$this->postJson($this->url('receipt'),$receipt)->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$a,'status'=>'completed','unit_cost_price'=>400]);$this->assertDatabaseHas('item_orders',['id'=>$b,'status'=>'completed']);
        $this->assertEquals(30000,$this->seller->fresh()->balance);$this->assertNotNull($this->account->fresh()->last_synced_at);
        $this->assertEquals(70,DB::table('nro_inventory_items')->where('account_id',$this->account->id)->value('quantity'));
        $this->assertEquals(0,DB::table('nro_inventory_items')->where('account_id',$this->account->id)->value('reserved'));
        $this->assertSame(70,app(NroShopService::class)->listing(DB::table('item_listings')->find($this->listing))['available']);
        $this->order(1);
    }
    public function test_partial_batch_can_start_next_round_with_pending_receipt():void {
        $this->order(10);$job=$this->claim();$first=$this->plan([$job]);$first['members'][0]['checkpoint'][0]['offered']=4;
        $this->postJson($this->url('begin'),$first)->assertOk();$receipt=$this->receiptPayload($first);
        $second=$this->plan([$job]);$second['members'][0]['checkpoint'][0]['delivered']=4;$second['members'][0]['checkpoint'][0]['offered']=6;
        $second['receipts']=[$receipt];$this->postJson($this->url('begin'),$second)->assertOk();
        $this->assertEquals(4,DB::table('item_order_items')->where('order_id',$job['order']['id'])->value('delivered'));
        $this->postJson($this->url('receipt'),$this->receiptPayload($second))->assertOk();
        $this->assertEquals(10000,$this->seller->fresh()->balance);
    }
    public function test_bad_member_rolls_back_the_whole_receipt():void {
        $this->order();$this->order();$plan=$this->plan([$this->claim(),$this->claim()]);$this->postJson($this->url('begin'),$plan)->assertOk();
        $result=$this->receiptPayload($plan);$result['members'][1]['items'][0]['delivered']=99;
        $this->postJson($this->url('receipt'),$result)->assertStatus(422);
        $this->assertEquals(0,DB::table('item_order_items')->sum('delivered'));$this->assertEquals(0,$this->seller->fresh()->balance);
    }
    public function test_different_buyers_cannot_be_combined_even_with_same_game_name():void {
        $this->order();$this->order(10,User::factory()->create(['balance'=>100000]));$plan=$this->plan([$this->claim(),$this->claim()]);
        $this->postJson($this->url('begin'),$plan)->assertStatus(409);$this->assertDatabaseCount('nro_delivery_rounds',0);
    }
    public function test_full_stock_rejects_a_capture_from_before_another_batch():void {
        $this->order();$job=$this->claim();$plan=$this->plan([$job]);$this->postJson($this->url('begin'),$plan)->assertOk();
        $this->postJson($this->url('receipt'),$this->receiptPayload($plan))->assertOk();
        $body=['workerInstance'=>$this->instance,'jobId'=>$job['id'],'leaseToken'=>$job['leaseToken'],'payload'=>$this->payload(100),'afterBatchKey'=>(string)Str::uuid()];
        $this->postJson($this->url('stock'),$body)->assertStatus(409);
        $this->assertEquals(90,DB::table('nro_inventory_items')->value('quantity'));
        $this->postJson($this->url('stock'),[...$body,'afterBatchKey'=>$plan['batchKey'],'payload'=>$this->payload(90)])->assertOk();
    }
    public function test_same_receiver_account_can_receive_two_orders_using_one_warehouse():void {
        $this->order(10,null,'auto');$this->order(10,null,'auto');$this->claim();$this->claim();
        $this->assertSame(2,DB::table('nro_delivery_sessions')->whereNotNull('receiver_lock')->count());
    }
    public function test_cost_is_private_and_nullable_and_frozen_on_purchase():void {
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonMissingPath('data.0.costPrice')->assertJsonMissingPath('data.0.cost_price');
        $this->getJson('/api/nro-shop/listings/'.$this->listing)->assertOk()->assertJsonMissingPath('data.costPrice');
        $this->getJson('/admin/nro-shop/listings')->assertOk()->assertJsonPath('data.0.costPrice','400');
        $order=$this->order();$this->patchJson('/admin/nro-shop/listings/'.$this->listing,['costPrice'=>null])->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'unit_cost_price'=>400]);$this->assertDatabaseHas('item_listings',['id'=>$this->listing,'cost_price'=>null]);
        $other=User::factory()->create();$other->assignRole('ctv');$other->givePermissionTo(['item-listings.view','item-listings.manage']);
        $this->actingAs($other,'web')->getJson('/admin/nro-shop/listings')->assertOk()->assertJsonCount(0,'data');
        $this->patchJson('/admin/nro-shop/listings/'.$this->listing,['costPrice'=>1])->assertNotFound();
    }
    public function test_empty_automatic_listing_is_removed_before_pagination_and_returns_after_restock():void {
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonPath('total',1);
        app(NroSnapshotService::class)->ingest($this->account,$this->payload(0));
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonPath('total',0)->assertJsonCount(0,'data');
        app(NroSnapshotService::class)->ingest($this->account,$this->payload(100));
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonPath('total',1);
        DB::table('nro_worker_keys')->update(['last_used_at'=>now()->subHour()]);\App\Support\ApiCache::clearGroup('public:nro-shop:listings');
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.available',0);
    }

    public function test_cancelled_receipt_releases_every_order_without_stock_or_balance_changes():void {
        $this->order();$this->order();$plan=$this->plan([$this->claim(),$this->claim()]);$this->postJson($this->url('begin'),$plan)->assertOk();
        $receipt=$this->receiptPayload($plan);$receipt['outcome']='cancelled';
        foreach($receipt['members'] as &$member)foreach($member['items'] as &$line)$line['delivered']=0;unset($member,$line);
        $this->postJson($this->url('receipt'),$receipt)->assertOk();$this->postJson($this->url('receipt'),$receipt)->assertOk();
        $this->assertEquals(2,DB::table('nro_delivery_sessions')->where('status','suspended')->where('trade_in_flight',false)->count());
        $this->assertEquals(100,DB::table('nro_inventory_items')->value('quantity'));$this->assertEquals(0,$this->seller->fresh()->balance);
        $this->postJson($this->url('receipt'),$this->receiptPayload($plan))->assertStatus(422);
    }
    public function test_cancel_before_delayed_begin_cannot_leave_a_started_round():void {
        $this->order();$plan=$this->plan([$this->claim()]);$receipt=$this->receiptPayload($plan);$receipt['outcome']='cancelled';$receipt['members'][0]['items'][0]['delivered']=0;
        $this->postJson($this->url('receipt'),$receipt)->assertOk();$this->postJson($this->url('begin'),$plan)->assertStatus(409);
        $this->assertDatabaseCount('nro_delivery_rounds',0);
    }
    public function test_late_cancel_after_lease_recovery_clears_the_group_checkpoint():void {
        $this->order();$job=$this->claim();$plan=$this->plan([$job]);$this->postJson($this->url('begin'),$plan)->assertOk();
        DB::table('nro_worker_jobs')->where('id',$job['id'])->update(['lease_until'=>now()->subMinute()]);
        app(\App\Services\NroDeliveryLifecycle::class)->expire();
        $receipt=$this->receiptPayload($plan);$receipt['outcome']='cancelled';$receipt['disrupted']=true;$receipt['members'][0]['items'][0]['delivered']=0;
        $this->postJson($this->url('receipt'),$receipt)->assertOk();
        $this->assertEquals(0,DB::table('nro_worker_jobs')->whereNotNull('recovery_json')->count());
        $this->assertEquals(0,DB::table('nro_delivery_rounds')->where('status','started')->count());
        $this->claim();
    }
    public function test_late_success_after_lease_recovery_is_applied_once():void {
        $order=$this->order();$job=$this->claim();$plan=$this->plan([$job]);$this->postJson($this->url('begin'),$plan)->assertOk();
        DB::table('nro_worker_jobs')->where('id',$job['id'])->update(['lease_until'=>now()->subMinute()]);app(\App\Services\NroDeliveryLifecycle::class)->expire();
        $this->postJson($this->url('receipt'),$this->receiptPayload($plan))->assertOk();$this->postJson($this->url('receipt'),$this->receiptPayload($plan))->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'completed']);$this->assertEquals(10000,$this->seller->fresh()->balance);
        $this->assertEquals(0,DB::table('nro_worker_jobs')->whereIn('status',['queued','processing'])->count());
    }
    public function test_stale_missing_snapshot_does_not_enable_refund_or_replace_stock():void {
        $first=$this->order();$this->order();$a=$this->claim();$b=$this->claim();$plan=$this->plan([$b]);
        $this->postJson($this->url('begin'),$plan)->assertOk();$this->postJson($this->url('receipt'),$this->receiptPayload($plan))->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$a['id'].'/complete',['leaseToken'=>$a['leaseToken'],'outcome'=>'missing_items','payload'=>$this->payload(0),'afterBatchKey'=>null])->assertOk()->assertJsonPath('retrySafe',true);
        $this->assertEquals(90,DB::table('nro_inventory_items')->value('quantity'));$this->assertDatabaseHas('item_orders',['id'=>$first,'refund_requested'=>false]);
    }
    public function test_receiver_errors_stay_on_order_without_refund_or_account_wide_lock():void {
        $order=$this->order(10,null,'auto');$job=$this->claim();
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'login_failed','loginFailureKind'=>'receiver_power_low','loginAccountRole'=>'receiver','retryable'=>false])->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'awaiting_receipt','refund_requested'=>false]);
        $this->assertStringContainsString('340.000',DB::table('item_orders')->where('id',$order)->value('public_failure'));
        $this->assertDatabaseHas('nro_accounts',['id'=>$this->account->id,'login_sale_blocked'=>false]);
        $this->assertSame(90,app(NroShopService::class)->listing(DB::table('item_listings')->find($this->listing))['available']);
    }

    public function test_recovery_claim_identifies_the_group_instead_of_comparing_each_order_separately():void {
        $this->order();$job=$this->claim();$plan=$this->plan([$job]);$this->postJson($this->url('begin'),$plan)->assertOk();
        DB::table('nro_worker_jobs')->where('id',$job['id'])->update(['lease_until'=>now()->subMinute()]);app(\App\Services\NroDeliveryLifecycle::class)->expire();
        $this->postJson('/app/nro-worker/claim',['protocolVersion'=>5,'workerInstance'=>$this->instance,'types'=>['delivery']])->assertOk()->assertJsonPath('data.recoveryBatchKey',$plan['batchKey']);
    }

    public function test_settling_a_batch_keeps_the_other_customer_rendezvous():void {
        $this->order();$this->order();$this->order();$a=$this->claim();$b=$this->claim();$waiting=$this->claim();
        $plan=$this->plan([$a,$b]);$this->postJson($this->url('begin'),$plan)->assertOk();
        $this->postJson($this->url('receipt'),$this->receiptPayload($plan))->assertOk();
        $session=DB::table('nro_delivery_sessions')->where('order_id',$waiting['order']['id'])->first();
        $this->assertSame('ready',$session->status);$this->assertFalse((bool)$session->trade_in_flight);
        $position=json_decode($session->position_json,true);$this->assertSame(5,$position['mapId']);$this->assertSame(20,$position['zone']);
        $this->assertSame('ready',$this->account->fresh()->delivery_activity['phase']);
        $this->postJson($this->url('begin'),$this->plan([$waiting]))->assertOk();
    }
}
