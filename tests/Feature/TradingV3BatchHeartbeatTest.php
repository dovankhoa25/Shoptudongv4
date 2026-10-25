<?php

namespace Tests\Feature;

use App\Models\{Bot, GemBot, GemTransaction, GoldTransaction, InventoryMovement, Server, User};
use App\Services\TradingV3\BatchHeartbeatService;
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Event};
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingV3BatchHeartbeatTest extends TestCase
{
    use RefreshDatabase;
    private string $worker='00000000-0000-4000-8000-000000000111';
    private string $other='00000000-0000-4000-8000-000000000112';
    private string $secret='batch-heartbeat-test-secret-at-least-32';
    private Server $server;
    private int $loginId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading_v3',require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled',true);
        config()->set('trading_v3.hmac_secret',$this->secret);
        Event::fake();
        $this->server=Server::create(['name'=>'batch-test','status'=>true]);
        $this->loginId=(int)DB::table('server_game_login')->max('id')+1;
        DB::table('server_game_login')->insert(['id'=>$this->loginId,'name'=>'login-test','ip'=>'test.example','port'=>'14445']);
    }
    private function signed(string $path,array $data,string $method='POST')
    {
        $body=$method==='GET'?'':json_encode($data);
        $time=(string)time();$nonce=bin2hex(random_bytes(16));$tool='nro-trading-v3';
        $headers=['X-Tool-Id'=>$tool,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,
            'X-Tool-Signature'=>hash_hmac('sha256',implode("\n",[$method,$path,$tool,$time,$nonce,hash('sha256',$body)]),$this->secret)];
        return $method==='GET'?$this->get($path,[...$headers,'Accept'=>'application/json']):$this->json($method,$path,$data,$headers);
    }
    private function bot(bool $gem=true,bool $enabled=true): GemBot|Bot
    {
        $attributes=['name'=>'batch-bot','account_name'=>'fake-'.Str::uuid(),'account_password'=>'fake-test-password',
            'server_id'=>$this->server->id,'server_game_id'=>$this->loginId,'map_id'=>'84',
            'area_number'=>'41','coordinates'=>'652-336','status'=>$enabled];
        return $gem?GemBot::create([...$attributes,'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>3])
            :Bot::create([...$attributes,'type'=>'selling_main','gold_bar_qty'=>10,'gold_qty'=>0]);
    }
    private function heartbeat(array $gems=[],array $gold=[],int $interval=150,?string $worker=null)
    {
        return $this->signed('/app/v3/heartbeat',['worker_id'=>$worker??$this->worker,
            'renewal_interval_seconds'=>$interval,'gem_bot_ids'=>$gems,'gold_bot_ids'=>$gold]);
    }
    private function order(GemBot|Bot $bot,?int $version,?string $owner): GemTransaction|GoldTransaction
    {
        $attributes=['bot_id'=>$bot->id,'server_id'=>$bot->server_id,'user_id'=>User::factory()->create()->id,
            'character_name'=>'test-client','status'=>'processing','delivery_data'=>['claim'=>['marker'=>'checkpoint']],
            'amount_vnd'=>10000,'price_at_transaction'=>1000];
        return $bot instanceof GemBot?GemTransaction::create([...$attributes,'gem_qty'=>20])
            :GoldTransaction::create([...$attributes,'type'=>'order','gold_qty'=>37000000,'gold_bar_qty'=>1,'pure_gold_qty'=>0]);
    }
    public function test_policy_disables_heartbeat_and_legacy_batch_returns_ack_without_lease(): void
    {
        $this->signed('/app/v3/config',[],'GET')->assertOk()->assertJsonPath('data.batch_heartbeat_supported',false)
            ->assertJsonPath('data.bot_lease_required',false)->assertJsonPath('data.heartbeat_required',false)
            ->assertJsonPath('data.lease_seconds',0)->assertJsonPath('data.renewal_interval_seconds',0);
        $gem=$this->bot();$gold=$this->bot(false);
        $beforeGem=$gem->fresh()->getRawOriginal();$beforeGold=$gold->fresh()->getRawOriginal();
        $response=$this->heartbeat([$gem->id],[$gold->id],600)->assertOk()->assertJsonPath('purpose','compatibility')
            ->assertJsonPath('lease_seconds',0)->assertJsonPath('renewal_interval_seconds',0)
            ->assertJsonPath('gem_bots.0.code','compatibility')->assertJsonPath('gem_bots.0.lease_until',null)
            ->assertJsonPath('gold_bots.0.granted',true);
        $this->assertSame($beforeGem,$gem->fresh()->getRawOriginal());$this->assertSame($beforeGold,$gold->fresh()->getRawOriginal());
        $this->assertStringNotContainsString('fake-test-password',$response->getContent());
        $this->assertStringNotContainsString('account_password',$response->getContent());
        $this->assertDatabaseCount('inventory_movements',0);
    }
    public function test_compatibility_batch_does_not_interpret_bot_status_login_or_old_owner(): void
    {
        $allowed=$this->bot();$off=$this->bot(true,false);$taken=$this->bot();$invalidLogin=$this->bot(false);
        $this->server->update(['status'=>false]);DB::table('server_game_login')->where('id',$this->loginId)->update(['port'=>0]);
        $before=$taken->fresh()->getRawOriginal();
        $this->heartbeat([$taken->id,999999,$off->id,$allowed->id],[$invalidLogin->id])->assertOk()
            ->assertJsonPath('gem_bots.0.id',$allowed->id)->assertJsonPath('gem_bots.1.id',$off->id)
            ->assertJsonPath('gem_bots.2.id',$taken->id)->assertJsonPath('gem_bots.3.id',999999)
            ->assertJsonPath('gem_bots.3.code','compatibility')->assertJsonPath('gold_bots.0.code','compatibility');
        $this->assertSame($before,$taken->fresh()->getRawOriginal());$this->assertFalse($off->fresh()->status);
        $this->signed('/app/v3/gem/bots',[],'GET')->assertOk()->assertJsonCount(3,'data')
            ->assertJsonPath('data.1.status',false)->assertJsonPath('data.0.server_enabled',false);
    }
    public function test_compatibility_batch_executes_zero_database_queries_even_for_large_lists(): void
    {
        DB::enableQueryLog();DB::flushQueryLog();
        $data=app(BatchHeartbeatService::class)->renew(['worker_id'=>$this->worker,'gem_bot_ids'=>range(1,1000),'gold_bot_ids'=>range(1,1000)]);
        $queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertSame([],$queries);$this->assertCount(1000,$data['gem_bots']);$this->assertCount(1000,$data['gold_bots']);
        $this->assertSame(0,$data['lease_seconds']);
    }
    public function test_batch_and_legacy_bot_actions_do_not_invalidate_optional_public_cache(): void
    {
        $gem=$this->bot();$gold=$this->bot(false);$groups=['public:gembot','public:server-prices','public:bots'];
        foreach($groups as $group)Cache::forever(ApiCache::key('generation',$group),'before');
        $this->heartbeat([$gem->id],[$gold->id])->assertOk();
        foreach([['gem',$gem],['gold',$gold]] as [$type,$bot]) {
            foreach(['lease','release'] as $action) $this->signed("/app/v3/{$type}/bots/{$bot->id}",['worker_id'=>$this->other,'action'=>$action],'PUT')->assertOk();
        }
        foreach($groups as $group)$this->assertSame('before',Cache::get(ApiCache::key('generation',$group)));
    }
    public function test_legacy_bot_actions_preserve_disabled_bot_and_unfinished_workflow_state(): void
    {
        $gem=$this->bot();$gold=$this->bot(false);
        foreach([$gem,$gold] as $bot)$bot->forceFill(['status'=>false])->save();
        $gemOrder=$this->order($gem,3,$this->other);$goldOrder=$this->order($gold,3,$this->other);
        InventoryMovement::create(['server_id'=>$gold->server_id,'bot_id'=>$gold->id,'bot_type'=>'bot','asset_type'=>'gold_bar',
            'movement_type'=>'reservation','quantity_delta'=>0,'balance_before'=>10,'balance_after'=>10,
            'transaction_type'=>'gold_transfer','idempotency_key'=>'batch-transfer-test','source'=>'app','occurred_at'=>now(),
            'meta'=>['status'=>'claimed','from_bot_id'=>$gold->id,'to_bot_id'=>$gold->id+1,'bars_qty'=>0,'pure_gold_qty'=>0]]);
        $beforeBots=[$gem->fresh()->getRawOriginal(),$gold->fresh()->getRawOriginal()];
        $beforeOrders=[$gemOrder->fresh()->getRawOriginal(),$goldOrder->fresh()->getRawOriginal()];
        foreach([['gem',$gem],['gold',$gold]] as [$type,$bot]) {
            foreach(['lease','release'] as $action)$this->signed("/app/v3/{$type}/bots/{$bot->id}",['worker_id'=>$this->worker,'action'=>$action],'PUT')->assertOk();
        }
        $this->heartbeat([$gem->id],[$gold->id],150,$this->worker)->assertOk();
        $this->assertSame($beforeBots,[$gem->fresh()->getRawOriginal(),$gold->fresh()->getRawOriginal()]);
        $this->assertSame($beforeOrders,[$gemOrder->fresh()->getRawOriginal(),$goldOrder->fresh()->getRawOriginal()]);
        $this->assertDatabaseCount('inventory_movements',1);
    }
    public function test_order_lists_include_other_worker_checkpoints_and_exclude_completed_orders(): void
    {
        $gem=$this->bot();$gold=$this->bot(false);
        $this->order($gem,3,$this->worker);$this->order($gem,3,$this->other);
        $this->order($gold,3,$this->worker);$this->order($gold,3,$this->other);
        $gemDone=$this->order($gem,3,$this->worker);$gemDone->update(['status'=>'completed']);
        $goldDone=$this->order($gold,3,$this->worker);$goldDone->update(['status'=>'completed']);
        $this->signed('/app/v3/gem-transactions?worker_id='.$this->worker,[],'GET')->assertOk()->assertJsonCount(2,'data');
        $this->signed('/app/v3/gold-transactions?worker_id='.$this->worker,[],'GET')->assertOk()->assertJsonCount(2,'data');
        $this->assertSame(2,GemTransaction::where('status','processing')->count());
    }
    public function test_stock_mutations_still_update_acknowledged_inventory_without_presence_request(): void
    {
        $gem=$this->bot();$gold=$this->bot(false);
        $this->signed('/app/v3/gem/bots/'.$gem->id,['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,
            'gem_qty'=>90,'gift_ticket_qty'=>9,'consign_item_qty'=>2],'PUT')->assertOk();
        $this->signed('/app/v3/gold/bots/'.$gold->id,['worker_id'=>$this->worker,'action'=>'stock','stock_version'=>1,
            'gold_bar_qty'=>8,'gold_qty'=>0],'PUT')->assertOk();
        $this->assertSame(90,$gem->fresh()->gem_qty);$this->assertSame(8,$gold->fresh()->gold_bar_qty);
        $this->assertDatabaseCount('inventory_movements',4);
    }
    public function test_auth_and_legacy_validation_still_apply_without_granting_permission(): void
    {
        $this->postJson('/app/v3/heartbeat',['worker_id'=>$this->worker])->assertUnauthorized();
        $this->heartbeat([999999],[],3600)->assertOk()->assertJsonPath('lease_seconds',0);
        $this->heartbeat([999999],[],3601)->assertUnprocessable();
        $this->heartbeat([999999,999999])->assertUnprocessable();
        $this->signed('/app/v3/heartbeat',['worker_id'=>$this->worker,'gem_bot_ids'=>[999999],'status'=>false,'map_id'=>1])->assertUnprocessable();
    }
}
