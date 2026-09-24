<?php
namespace Tests\Feature;

use App\Models\Category;
use App\Events\NroShopUpdated;
use App\Models\GameType;
use App\Models\Nick;
use App\Models\NroAccount;
use App\Models\User;
use App\Services\NroSnapshotService;
use App\Services\NroShopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use App\Services\NroReceivingService;
use App\Services\NroDeliveryLifecycle;

class NroDeliveryRecoveryRegressionTest extends TestCase
{
    use RefreshDatabase;
    private string $token;
    protected function setUp(): void
    {
        parent::setUp(); $this->withoutMiddleware(ThrottleRequests::class);
        Permission::findOrCreate('nicks.manage', 'web'); Role::findOrCreate('ctv', 'web'); Role::findOrCreate('admin', 'web');
        $this->token = 'nrow_'.Str::random(64);
        DB::table('nro_worker_keys')->insert(['name' => 'test', 'token_hash' => hash('sha256', $this->token), 'accepts_delivery' => true, 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    private function seller(): User
    {
        $user = User::factory()->create(['balance' => 0]); $user->assignRole('ctv'); $user->givePermissionTo(['nicks.manage','nro-accounts.manage','item-listings.manage']); return $user;
    }
    private function payload(int $count = 2, bool $chestComplete = true): array
    {
        return ['schemaVersion' => 1, 'catalogVersion' => '17', 'completeness' => ['bag' => true, 'chest' => $chestComplete, 'equipped'=>true],
            'snapshot' => ['capturedAt' => now()->toIso8601String(), 'password' => 'NEVER_PUBLIC', 'character' => ['id' => 10, 'name' => 'botgame', 'gender' => 0, 'power' => 100000, 'password' => 'NEVER_PUBLIC'],
                'equipped' => [], 'chest' => [], 'collectionChest' => [], 'bag' => $count > 0 ? [
                    ['slot' => 0, 'templateId' => 0, 'quantity' => $count, 'options' => [['optionId' => 0, 'param' => 100]]],
                    ['slot' => 1, 'templateId' => 0, 'quantity' => $count, 'options' => [['optionId' => 0, 'param' => 150]]],
                ] : []]];
    }
    private function warehouse(User $seller): NroAccount
    {
        DB::table('servers')->insertOrIgnore(['id' => 10, 'name' => 'sv10', 'name_view' => 'Server test', 'status' => true]);
        if (!DB::table('server_game_login')->where('id', 37)->exists()) DB::table('server_game_login')->insert(['id' => 37, 'name' => 'Login test', 'ip' => '127.0.0.1', 'port' => '14445']);
        $a = NroAccount::create(['user_id' => $seller->id, 'account_name' => 'acc'.$seller->id, 'game_password' => 'secret-pass', 'server' => 'vt1', 'server_index' => 0, 'server_id' => 10, 'server_game_id' => 37, 'usage_type' => 'warehouse', 'status' => 'active']);
        app(NroSnapshotService::class)->ingest($a, $this->payload()); return $a->fresh();
    }
    private function listing(User $seller, NroAccount $a): int
    {
        return $this->actingAs($seller, 'web')->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings', [
            'title' => 'Hai món khác option', 'price' => 200, 'items' => DB::table('nro_inventory_items')->where('account_id', $a->id)->get()->map(fn ($i) => ['id' => $i->id, 'quantity' => 1])->all(),
        ])->assertOk()->json('id');
    }
    private function recoveryFixture(string $mode = 'manual'): array
    {
        $seller=$this->seller(); $account=$this->warehouse($seller);
        $listing=$this->listing($seller,$account); $buyer=User::factory()->create(['balance'=>1000]);
        $order=app(NroShopService::class)->purchase($buyer,$listing,'khach',10,(string)Str::uuid());
        $session=app(\App\Services\NroReceivingService::class)->start($buyer,$order,[
            'mode'=>$mode,'recipientName'=>'khach','username'=>'test-receiver','password'=>'test-only','requestKey'=>(string)Str::uuid()]);
        $job=$this->withToken($this->token)->postJson('/app/nro-worker/claim',[
            'protocolVersion'=>4,'workerInstance'=>(string)Str::uuid(),'types'=>['delivery']])->assertOk()->json('data');
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/ready',[
            'leaseToken'=>$job['leaseToken'],'characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>7,'recipientName'=>'khach'])->assertOk();
        return [$account,$buyer,$order,$session,$job];
    }
    private function beginCheckpointRound(array $job): array {
        $key=(string)Str::uuid();$checkpoint=array_map(fn($i)=>['id'=>$i['id'],'before'=>2,'offered'=>$i['quantity']-$i['delivered'],'delivered'=>$i['delivered']],$job['order']['items']);
        $body=['leaseToken'=>$job['leaseToken'],'roundKey'=>$key,'checkpoint'=>$checkpoint];
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/begin-round',$body)->assertOk();
        return [$key,$checkpoint,$body];
    }

    private function claimBody(array $job): array {
        return ['protocolVersion'=>4,'workerInstance'=>DB::table('nro_worker_jobs')->where('id',$job['id'])->value('worker_instance'),'types'=>['delivery']];
    }
    private function secondOrder(NroAccount $a, User $buyer, string $mode='manual'): int {
        $listing=$this->listing(User::find($a->user_id),$a);
        $order=app(NroShopService::class)->purchase($buyer,$listing,'another',10,(string)Str::uuid());
        app(NroReceivingService::class)->start($buyer,$order,['mode'=>$mode,'recipientName'=>'another','username'=>'another-login','password'=>'test-only','requestKey'=>(string)Str::uuid()]);
        return $order;
    }
    private function pair(): array {
        [$a,$buyer,$order,$session,$first]=$this->recoveryFixture();
        $this->secondOrder($a,$buyer);$claim=$this->claimBody($first);
        $second=$this->postJson('/app/nro-worker/claim',$claim)->assertOk()->json('data');
        $this->assertNotNull($second);
        $this->postJson('/app/nro-worker/jobs/'.$second['id'].'/ready',['leaseToken'=>$second['leaseToken'],'characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>22,'recipientName'=>'another'])->assertOk();
        return [$a,$first,$second,$claim];
    }
    public function test_recovery_claims_alongside_shared_waiter_without_unlocking_trade_early(): void {
        [$a,$first,$second,$claim]=$this->pair();
        [,$checkpoint]=$this->beginCheckpointRound($first);
        $this->postJson('/app/nro-worker/jobs/'.$first['id'].'/complete',['leaseToken'=>$first['leaseToken'],'outcome'=>'reconnect_check','recovery'=>$checkpoint])->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$second['id'].'/begin-round',['leaseToken'=>$second['leaseToken']])->assertStatus(409);
        $recovery=$this->postJson('/app/nro-worker/claim',$claim)->assertOk()->json('data');
        $this->assertNotNull($recovery);$this->assertEquals($first['order']['id'],$recovery['order']['id']);
        $this->postJson('/app/nro-worker/jobs/'.$second['id'].'/begin-round',['leaseToken'=>$second['leaseToken']])->assertStatus(409);
        $this->postJson('/app/nro-worker/jobs/'.$recovery['id'].'/recovery-checked',['leaseToken'=>$recovery['leaseToken']])->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$second['id'].'/begin-round',['leaseToken'=>$second['leaseToken']])->assertOk();
    }
    public function test_multiple_queued_recoveries_choose_one_owner(): void {
        [$a,$first,$second,$claim]=$this->pair();
        foreach([$first,$second] as $job) DB::table('nro_worker_jobs')->where('id',$job['id'])->update(['status'=>'queued','recovery_json'=>json_encode(array_map(fn($i)=>['id'=>$i['id'],'before'=>2,'offered'=>1,'delivered'=>0],$job['order']['items']))]);
        $next=$this->postJson('/app/nro-worker/claim',$claim)->assertOk()->json('data');
        $this->assertEquals($first['id'],$next['id']);
        $this->postJson('/app/nro-worker/claim',$claim)->assertOk()->assertJsonPath('data',null);
        $this->postJson('/app/nro-worker/jobs/'.$next['id'].'/recovery-checked',['leaseToken'=>$next['leaseToken']])->assertOk();
        $this->postJson('/app/nro-worker/claim',$claim)->assertOk()->assertJsonPath('data.id',$second['id']);
    }
    public function test_failed_preparation_unpauses_waiters_and_keeps_their_remaining_time(): void {
        [$a,$first,$second,$claim]=$this->pair();
        $this->postJson('/app/nro-worker/jobs/'.$first['id'].'/warehouse-state',['leaseToken'=>$first['leaseToken'],'phase'=>'home'])->assertOk();
        $this->travel(61)->minutes();
        DB::table('nro_worker_jobs')->whereIn('id',[$first['id'],$second['id']])->update(['lease_until'=>now()->addMinutes(3)]);
        $this->postJson('/app/nro-worker/jobs/'.$first['id'].'/complete',['leaseToken'=>$first['leaseToken'],'outcome'=>'interrupted'])->assertOk();
        $this->assertNull($a->fresh()->delivery_activity);
        $waiter=DB::table('nro_delivery_sessions')->where('order_id',$second['order']['id'])->first();
        $this->assertNull($waiter->position_json);$this->assertTrue(\Carbon\Carbon::parse($waiter->expires_at)->isFuture());
        app(NroDeliveryLifecycle::class)->expire();
        $this->assertDatabaseHas('nro_worker_jobs',['id'=>$second['id'],'status'=>'processing']);
        $this->postJson('/app/nro-worker/jobs/'.$second['id'].'/begin-round',['leaseToken'=>$second['leaseToken']])->assertOk();
    }
    public function test_other_job_cannot_release_current_preparation_owner(): void {
        [$a,$first,$second]=$this->pair();
        $this->postJson('/app/nro-worker/jobs/'.$first['id'].'/warehouse-state',['leaseToken'=>$first['leaseToken'],'phase'=>'home'])->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$second['id'].'/complete',['leaseToken'=>$second['leaseToken'],'outcome'=>'interrupted'])->assertOk();
        $this->assertEquals($first['id'],$a->fresh()->delivery_activity['jobId']);
        $this->assertNotNull($a->fresh()->delivery_activity['pauseStartedAt']);
    }
    public function test_auto_receiver_queue_is_bounded_and_pending_receipts_block_only_their_warehouse(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture('auto');
        $other=$this->secondOrder($a,$buyer,'auto');$claim=$this->claimBody($job);
        $this->postJson('/app/nro-worker/claim',$claim)->assertOk()->assertJsonPath('data',null);
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'login_failed','loginAccountRole'=>'receiver','loginFailureKind'=>'BadCredentials','retryable'=>false])->assertOk();
        $this->postJson('/app/nro-worker/claim',[...$claim,'blockedAccountIds'=>[$a->id]])->assertOk()->assertJsonPath('data',null);
        $this->postJson('/app/nro-worker/claim',$claim)->assertOk()->assertJsonPath('data.order.id',$other);
    }
    public function test_receiver_password_error_can_be_corrected_without_refund_or_blocking_sender(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture('auto');
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'login_failed','loginAccountRole'=>'receiver','loginFailureKind'=>'BadCredentials','retryable'=>false,'message'=>'SECRET_RAW_GAME_MESSAGE'])->assertOk();
        $flow=app(NroShopService::class)->order($order)['flow'];
        $this->assertTrue($flow['canReceive']);$this->assertFalse($flow['canRequestRefund']);
        $this->assertStringContainsString('mật khẩu acc nhận',$flow['message']);$this->assertStringNotContainsString('SECRET',$flow['message']);
        $this->assertSame('Sửa thông tin nhận',$flow['receiveActionLabel']);
        $this->assertFalse($a->fresh()->login_sale_blocked);
        $new=app(NroReceivingService::class)->start($buyer,$order,['mode'=>'auto','username'=>'fixed','password'=>'test-fixed','requestKey'=>(string)Str::uuid()]);
        $this->assertNotEquals($session,$new);$this->assertSame(0,DB::table('item_order_items')->where('order_id',$order)->sum('delivered'));
    }
    public function test_receiver_retry_message_takes_precedence_over_checkpoint_recovery(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture('auto');
        $this->beginCheckpointRound($job);
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/heartbeat',['leaseToken'=>$job['leaseToken'],'loginWaiting'=>true,'loginAccountRole'=>'receiver','loginRetryAt'=>now()->addMinute()->toISOString()])->assertOk();
        $flow=app(NroShopService::class)->order($order)['flow'];
        $this->assertSame('retrying',$flow['phase']);$this->assertStringContainsString('Acc nhận',$flow['message']);
        $this->assertFalse($flow['canRequestRefund']);
    }
    public function test_rejected_receipt_replays_progress_and_settlement_exactly_once(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture();[$key]=$this->beginCheckpointRound($job);
        $url='/app/nro-worker/jobs/'.$job['id'];$lease=['leaseToken'=>$job['leaseToken']];
        $body=[...$lease,'roundKey'=>$key,'items'=>array_map(fn($i)=>['id'=>$i['id'],'delivered'=>$i['quantity']],$job['order']['items'])];
        $this->postJson($url.'/progress',$body)->assertOk();
        $this->postJson($url.'/result-issue',[...$lease,'httpStatus'=>422])->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'failure_code'=>'result_rejected']);
        $this->postJson($url.'/progress',$body)->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'failure_code'=>null]);
        $this->getJson($url.'/receipt-state')->assertOk()->assertJsonPath('rounds.0.key',$key)->assertJsonPath('rounds.0.status','confirmed');
        $this->postJson($url.'/complete',[...$lease,'outcome'=>'success'])->assertOk();
        $balance=User::find($a->user_id)->balance;
        $this->postJson($url.'/complete',[...$lease,'outcome'=>'success'])->assertOk();
        $this->postJson($url.'/progress',$body)->assertOk();
        $this->assertEquals($balance,User::find($a->user_id)->balance);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'completed']);
        $this->assertSame(2,DB::table('item_order_items')->where('order_id',$order)->sum('delivered'));
    }
    public function test_receipt_state_requires_worker_key_and_exposes_no_login_secrets(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture('auto');
        $url='/app/nro-worker/jobs/'.$job['id'].'/receipt-state';
        $json=$this->getJson($url)->assertOk()->assertHeader('Cache-Control','no-store, private')->json();
        $this->assertEqualsCanonicalizing(['accountId','orderId','jobStatus','orderStatus','rounds'],array_keys($json));
        $this->assertStringNotContainsString('test-only',json_encode($json));
        $this->withToken('invalid')->getJson($url)->assertUnauthorized();
        $this->withToken($this->token)->getJson('/app/nro-worker/jobs/999999/receipt-state')->assertNotFound()->assertJsonPath('code','job_missing');
    }
    public function test_requested_stock_audit_and_queued_recovery_do_not_block_each_other(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture();
        DB::table('nro_worker_jobs')->where('id',$job['id'])->update(['status'=>'queued','lease_until'=>now()->subMinute(),'recovery_json'=>'[]']);
        $audit=DB::table('nro_worker_jobs')->insertGetId(['account_id'=>$a->id,'audit_order_id'=>$order,'type'=>'snapshot','status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
        $claim=[...$this->claimBody($job),'types'=>['snapshot','delivery']];
        $this->postJson('/app/nro-worker/claim',$claim)->assertOk()->assertJsonPath('data.id',$audit);
        $this->assertDatabaseHas('nro_worker_jobs',['id'=>$job['id'],'status'=>'queued']);
    }

    public function test_maintenance_heartbeat_pauses_receipt_clock_and_preserves_receiver_credentials(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture('auto');
        $credentials=DB::table('nro_delivery_sessions')->where('id',$session)->value('receiver_credentials');
        $url='/app/nro-worker/jobs/'.$job['id'];$lease=['leaseToken'=>$job['leaseToken']];
        $this->postJson('/app/nro-worker/heartbeat-batch',['workerInstance'=>$this->claimBody($job)['workerInstance'],'jobs'=>[
            ['id'=>$job['id'],...$lease,'loginWaiting'=>true,'loginFailureKind'=>'ServerMaintenance','loginAccountRole'=>'receiver','loginRetryAt'=>now()->addMinutes(40)->toISOString()]
        ]])->assertOk()->assertJsonPath('results.0.ok',true);
        $this->assertDatabaseHas('nro_delivery_sessions',['id'=>$session,'expires_at'=>null,'position_json'=>null,'receiver_credentials'=>$credentials]);
        $this->postJson($url.'/heartbeat',[...$lease,'message'=>'position refresh'])->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'failure_code'=>'server_maintenance']);
        $flow=app(NroShopService::class)->order($order)['flow'];
        $this->assertSame('Server báo bảo trì',$flow['phaseLabel']);$this->assertFalse($flow['canRequestRefund']);
        $this->travel(41)->minutes();
        app(NroDeliveryLifecycle::class)->expire();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'queued']);
        $this->assertDatabaseHas('nro_delivery_sessions',['id'=>$session,'receiver_credentials'=>$credentials,'status'=>'queued']);
        $next=$this->postJson('/app/nro-worker/claim',$this->claimBody($job))->assertOk()->json('data');
        $this->assertNotNull($next);
        $this->postJson('/app/nro-worker/jobs/'.$next['id'].'/ready',['leaseToken'=>$next['leaseToken'],'characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>22,'recipientName'=>'khach'])->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'failure_code'=>null]);
        $this->assertTrue(\Carbon\Carbon::parse(DB::table('nro_delivery_sessions')->where('id',$session)->value('expires_at'))->isFuture());
    }
    public function test_silent_login_requeues_after_ten_minutes_instead_of_expiring_or_refunding(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture();
        $claim=$this->claimBody($job);
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'login_failed','loginFailureKind'=>'ServerUnresponsive','retryable'=>true,'loginAccountRole'=>'sender','loginRetryAt'=>now()->addMinutes(10)->toISOString()])->assertOk()->assertJsonPath('recovered',true);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'queued','failure_code'=>'server_unresponsive']);
        $this->assertDatabaseHas('nro_delivery_sessions',['id'=>$session,'expires_at'=>null]);
        $this->postJson('/app/nro-worker/claim',$claim)->assertOk()->assertJsonPath('data',null);
        $this->travel(11)->minutes();
        $next=$this->postJson('/app/nro-worker/claim',$claim)->assertOk()->json('data');$this->assertNotNull($next);
        $this->assertFalse(app(NroShopService::class)->order($order)['flow']['canRequestRefund']);
        $this->assertSame(0,DB::table('item_order_items')->where('order_id',$order)->sum('delivered'));
    }
    public function test_maintenance_cancel_preserves_evidence_and_automatically_requeues(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture('auto');[$key]=$this->beginCheckpointRound($job);
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'trade_paused','tradeEvidence'=>'server_cancelled','loginFailureKind'=>'ServerMaintenance','retryable'=>true,'loginAccountRole'=>'sender','loginRetryAt'=>now()->addMinutes(40)->toISOString()])->assertOk()->assertJsonPath('recovered',true);
        $this->assertDatabaseHas('nro_delivery_rounds',['round_key'=>$key,'status'=>'cancelled']);
        $this->assertDatabaseHas('nro_delivery_sessions',['id'=>$session,'status'=>'queued','trade_in_flight'=>false,'expires_at'=>null]);
        $this->assertNotNull(DB::table('nro_delivery_sessions')->where('id',$session)->value('receiver_credentials'));
        $this->assertDatabaseHas('item_orders',['id'=>$order,'failure_code'=>'server_maintenance','status'=>'queued']);
    }
    public function test_maintenance_reconnect_keeps_round_checkpoint_after_long_downtime(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture('auto');[,$checkpoint]=$this->beginCheckpointRound($job);
        $claim=$this->claimBody($job);
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'reconnect_check','recovery'=>$checkpoint,
            'loginFailureKind'=>'ServerMaintenance','retryable'=>true,'loginAccountRole'=>'sender','loginRetryAt'=>now()->addMinutes(40)->toISOString()])->assertOk();
        $this->assertDatabaseHas('nro_delivery_sessions',['id'=>$session,'status'=>'queued','expires_at'=>null]);
        $this->postJson('/app/nro-worker/claim',$claim)->assertOk()->assertJsonPath('data',null);
        $this->travel(41)->minutes();app(NroDeliveryLifecycle::class)->expire();
        $next=$this->postJson('/app/nro-worker/claim',$claim)->assertOk()->json('data');
        $this->assertNotNull($next);$this->assertSame($checkpoint,$next['recovery']);
        $this->assertDatabaseHas('nro_delivery_sessions',['id'=>$session,'status'=>'preparing']);
        $this->assertSame(0,DB::table('item_order_items')->where('order_id',$order)->sum('delivered'));
    }
    public function test_maintenance_does_not_discard_confirmed_progress_or_settle_twice(): void {
        [$a,$buyer,$order,$session,$job]=$this->recoveryFixture();[$key]=$this->beginCheckpointRound($job);
        $url='/app/nro-worker/jobs/'.$job['id'];$lease=['leaseToken'=>$job['leaseToken']];
        $this->postJson($url.'/heartbeat',[...$lease,'loginWaiting'=>true,'loginFailureKind'=>'ServerMaintenance','loginRetryAt'=>now()->addMinutes(40)->toISOString()])->assertOk();
        $this->assertDatabaseHas('nro_delivery_sessions',['id'=>$session,'trade_in_flight'=>true]);
        $progress=[...$lease,'roundKey'=>$key,'items'=>array_map(fn($i)=>['id'=>$i['id'],'delivered'=>$i['quantity']],$job['order']['items'])];
        $this->postJson($url.'/progress',$progress)->assertOk();
        $this->postJson($url.'/complete',[...$lease,'outcome'=>'success','tradeEvidence'=>'server_success'])->assertOk();
        $balance=User::find($a->user_id)->balance;
        $this->postJson($url.'/progress',$progress)->assertOk();$this->postJson($url.'/complete',[...$lease,'outcome'=>'success'])->assertOk();
        $this->assertEquals($balance,User::find($a->user_id)->balance);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'completed','failure_code'=>null]);
    }
}
