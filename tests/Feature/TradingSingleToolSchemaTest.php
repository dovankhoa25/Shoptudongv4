<?php

namespace Tests\Feature;

use App\Models\{Bot,GemBot,GemTransaction,GoldTransaction,InventoryMovement,Server,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Event,Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingSingleToolSchemaTest extends TestCase
{
    use RefreshDatabase;
    private string $secret='simplified-state-test-only-secret-at-least-32';

    protected function setUp(): void
    {
        parent::setUp();Event::fake();
        config(['trading_v3.enabled'=>true,'trading_v3.hmac_secret'=>$this->secret]);
    }
    private function fixture(): array
    {
        $server=Server::create(['name'=>'schema-fixture','status'=>true]);
        $login=(int)DB::table('server_game_login')->max('id')+1;
        DB::table('server_game_login')->insert(['id'=>$login,'name'=>'schema-test','ip'=>'game-test.example','port'=>'14445']);
        $base=['name'=>'schema-bot','account_name'=>'test-only-account','account_password'=>'test-only-password',
            'server_id'=>$server->id,'server_game_id'=>$login,'map_id'=>'84','area_number'=>'1','coordinates'=>'652-336','status'=>true];
        $gem=GemBot::create([...$base,'gem_qty'=>100,'gift_ticket_qty'=>10,'consign_item_qty'=>3]);
        $gold=Bot::create([...$base,'account_name'=>'test-only-gold','type'=>'selling_main','gold_bar_qty'=>10,'gold_qty'=>0]);
        $user=User::factory()->create(['balance'=>50000]);
        $gemOrder=GemTransaction::create(['user_id'=>$user->id,'server_id'=>$server->id,'character_name'=>'schema-client','bot_id'=>$gem->id,
            'delivery_method'=>'gift_ticket','gem_qty'=>20,'amount_vnd'=>10000,'price_at_transaction'=>20,'status'=>'pending','updated_by'=>'web']);
        $goldOrder=GoldTransaction::create(['user_id'=>$user->id,'server_id'=>$server->id,'character_name'=>'schema-client','bot_id'=>$gold->id,
            'type'=>'order','gold_qty'=>74000000,'gold_bar_qty'=>2,'pure_gold_qty'=>0,'amount_vnd'=>10000,'price_at_transaction'=>1000,'status'=>'pending','updated_by'=>'web']);
        return [$gem,$gold,$gemOrder,$goldOrder,$user];
    }
    private function update(string $kind,int $id,array $data)
    {
        $path='/app/v3/'.$kind.'-transactions/'.$id;$body=json_encode($data);$time=(string)time();$nonce=bin2hex(random_bytes(16));$tool='nro-trading-v3';
        return $this->putJson($path,$data,['X-Tool-Id'=>$tool,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,
            'X-Tool-Signature'=>hash_hmac('sha256',implode("\n",['PUT',$path,$tool,$time,$nonce,hash('sha256',$body)]),$this->secret)]);
    }
    public function test_schema_drops_only_retired_ownership_and_keeps_financial_checkpoint_columns(): void
    {
        foreach(['bots','gem_bots'] as $table) {
            foreach(['v3_worker_id','v3_lease_until'] as $column)$this->assertFalse(Schema::hasColumn($table,$column));
            $this->assertTrue(Schema::hasColumn($table,'stock_version'));
        }
        foreach(['gem_transactions','gold_transactions'] as $table) {
            foreach(['processing_owner','processing_version','processing_token'] as $column)$this->assertFalse(Schema::hasColumn($table,$column));
            foreach(['bot_id','delivery_data','delivery_phase','processing_started_at','completed_at'] as $column)$this->assertTrue(Schema::hasColumn($table,$column));
            $this->assertTrue(Schema::hasIndex($table,$table.'_pending_status'));
        }
        foreach(['delivered_gem_qty','bot_gem_spent_qty','tickets_used_qty'] as $column)$this->assertTrue(Schema::hasColumn('gem_transactions',$column));
    }
    public function test_upgrade_preserves_stock_order_progress_history_and_frozen_delivery_data(): void
    {
        $this->assertSame('sqlite',DB::connection()->getDriverName());$this->assertSame(':memory:',config('database.connections.sqlite.database'));
        [$gem,$gold,$gemOrder,$goldOrder]=$this->fixture();
        $gem->forceFill(['stock_version'=>17])->save();$gold->forceFill(['stock_version'=>19])->save();
        $gemOrder->update(['status'=>'processing','bot_gem_spent_qty'=>10,'delivered_gem_qty'=>10,'tickets_used_qty'=>1,
            'delivery_phase'=>'gifting','delivery_data'=>['claim'=>['frozen'=>'gem-terms'],'last_step_id'=>'gem-proof'],'processing_started_at'=>now()]);
        $goldOrder->update(['status'=>'processing','delivery_phase'=>'trading','delivery_data'=>['claim'=>['frozen'=>'gold-terms'],
            'progress'=>['bars'=>1,'pure'=>0],'last_step_id'=>'gold-proof'],'processing_started_at'=>now()]);
        InventoryMovement::create(['server_id'=>$gem->server_id,'bot_id'=>$gem->id,'bot_type'=>'gem_bot','asset_type'=>'gem',
            'movement_type'=>'sale','quantity_delta'=>-10,'balance_before'=>100,'balance_after'=>90,'transaction_id'=>$gemOrder->id,
            'transaction_type'=>'gem_transaction','idempotency_key'=>'migration-keep-proof','source'=>'app','occurred_at'=>now(),'meta'=>['payload_hash'=>'original-hash']]);
        $migration=require base_path('database/migrations/2026_10_15_000001_simplify_single_tool_trading_state.php');$migration->down();
        foreach(['bots','gem_bots'] as $table)DB::table($table)->update(['v3_worker_id'=>'retired-worker','v3_lease_until'=>now()->addMinute()]);
        foreach(['gem_transactions','gold_transactions'] as $table)DB::table($table)->update(['processing_owner'=>'retired-worker','processing_version'=>3,'processing_token'=>'retired-token']);
        $before=[];$retired=['v3_worker_id','v3_lease_until','processing_owner','processing_version','processing_token'];
        foreach(['bots','gem_bots','gem_transactions','gold_transactions'] as $table)$before[$table]=array_diff_key((array)DB::table($table)->first(),array_flip($retired));
        $history=(array)DB::table('inventory_movements')->first();$migration->up();$migration->up();
        foreach($before as $table=>$row)$this->assertSame($row,(array)DB::table($table)->first(),$table);
        $this->assertSame($history,(array)DB::table('inventory_movements')->first());
        $this->assertTrue($gemOrder->fresh()->hasDeliveryCheckpoint());$this->assertTrue($goldOrder->fresh()->hasDeliveryCheckpoint());
        $migration->down();$migration->down();
        foreach(['gem_transactions','gold_transactions'] as $table)$this->assertNull(DB::table($table)->value('processing_token'));
        $this->assertSame('gem-terms',$gemOrder->fresh()->delivery_data['claim']['frozen']);
        $migration->up();
    }
    public function test_start_without_worker_or_token_marks_processing_before_game_and_cannot_reset_progress(): void
    {
        [$gem,$gold,$gemOrder,$goldOrder,$user]=$this->fixture();
        foreach([['gem',$gem,$gemOrder,'gifting'],['gold',$gold,$goldOrder,'waiting_customer']] as [$kind,$bot,$order,$phase]) {
            $order->update(['created_at'=>now()->subMinutes(11)]);
            $base=['action'=>'start','bot_id'=>$bot->id,'character_id'=>1234,'phase'=>$phase];
            $response=$this->update($kind,$order->id,$base)->assertOk()->assertJsonPath('data.status','processing')
                ->assertJsonPath('data.delivery_phase',$phase)->assertJsonPath('data.has_delivery_checkpoint',true);
            foreach(['processing_token','processing_owner','processing_version'] as $key)$this->assertArrayNotHasKey($key,$response->json('data'));
            $data=$order->fresh()->delivery_data;$this->assertNotEmpty($data['claim']);
            $advanced=$kind==='gem'?'gifting':'trading';
            $this->update($kind,$order->id,['action'=>'phase','bot_id'=>$bot->id,'character_id'=>1234,'phase'=>$advanced,'step_id'=>(string)Str::uuid()])->assertOk();
            $this->update($kind,$order->id,[...$base,'phase'=>'waiting_customer'])->assertOk()->assertJsonPath('data.delivery_phase',$advanced);
            $this->update($kind,$order->id,[...$base,'character_id'=>9999])->assertConflict();
            $this->update($kind,$order->id,['action'=>'refund_pending','bot_id'=>$bot->id,'step_id'=>(string)Str::uuid(),'timeout_seconds'=>600])->assertConflict();
            $this->assertSame('processing',$order->fresh()->status);
        }
        $this->assertSame(50000,(int)$user->fresh()->balance);$this->assertSame(100,$gem->fresh()->gem_qty);$this->assertSame(10,$gold->fresh()->gold_bar_qty);
        $this->assertDatabaseCount('inventory_movements',2); // Both explicit phases have durable workflow markers.
    }
    public function test_start_does_not_reopen_completed_or_refunded_orders(): void
    {
        [$gem,$gold,$gemOrder,$goldOrder]=$this->fixture();
        $gemOrder->update(['status'=>'refunded']);$goldOrder->update(['status'=>'completed']);
        foreach([['gem',$gem,$gemOrder,'refunded'],['gold',$gold,$goldOrder,'completed']] as [$kind,$bot,$order,$status]) {
            $before=$order->fresh()->getRawOriginal();
            $this->update($kind,$order->id,['action'=>'start','bot_id'=>$bot->id])->assertOk()->assertJsonPath('data.status',$status);
            $this->assertSame($before,$order->fresh()->getRawOriginal());
        }
    }
    public function test_gold_reordered_frozen_claim_remains_valid_but_changed_value_is_rejected(): void
    {
        [, $bot, , $order]=$this->fixture();
        $start=['action'=>'start','bot_id'=>$bot->id,'character_id'=>1234];
        $this->update('gold',$order->id,$start)->assertOk();
        $data=$order->fresh()->delivery_data;$data['claim']=array_reverse($data['claim'],true);
        $order->update(['delivery_data'=>$data]);
        $this->update('gold',$order->id,$start)->assertOk()->assertJsonPath('data.has_delivery_checkpoint',true);
        $changed=$data;$changed['claim']['amount_vnd']++;
        $order->update(['delivery_data'=>$changed]);$this->update('gold',$order->id,$start)->assertConflict();
        $order->update(['delivery_data'=>$data]);
        $this->update('gold',$order->id,['action'=>'receipt','bot_id'=>$bot->id,'character_id'=>1234,'step_id'=>(string)Str::uuid(),
            'bars_qty'=>2,'pure_gold_qty'=>0,'completed'=>true,'bot_snapshot'=>['stock_version'=>1,'gold_bar_qty'=>8,'gold_qty'=>0],
            'evidence'=>['kind'=>'sale','confirmed'=>true]])->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(8,$bot->fresh()->gold_bar_qty);
    }
    public function test_gem_reordered_equipment_and_option_keys_do_not_change_the_frozen_plan(): void
    {
        [$bot, , $order]=$this->fixture();$order->update(['delivery_method'=>'consignment']);
        $this->update('gem',$order->id,['action'=>'start','bot_id'=>$bot->id,'character_id'=>1234])->assertOk();
        $plan=['template_id'=>1,'signature'=>'1:2=1,87=0','quantity'=>1,'verified'=>false,'options'=>[['id'=>87,'param'=>0],['id'=>2,'param'=>1]]];
        $phase=['action'=>'phase','bot_id'=>$bot->id,'character_id'=>1234,'phase'=>'giving_item','delivery_data'=>['delivery_item'=>$plan]];
        $this->update('gem',$order->id,[...$phase,'step_id'=>(string)Str::uuid()])->assertOk();
        $data=$order->fresh()->delivery_data;$data['delivery_item']=array_reverse($data['delivery_item'],true);
        $data['delivery_item']['options']=array_map(fn($option)=>array_reverse($option,true),$data['delivery_item']['options']);
        $order->update(['delivery_data'=>$data]);
        $this->update('gem',$order->id,[...$phase,'step_id'=>(string)Str::uuid()])->assertOk();
        $changed=$phase;$changed['delivery_data']['delivery_item']['options'][0]['param']=1;
        $this->update('gem',$order->id,[...$changed,'step_id'=>(string)Str::uuid()])->assertConflict();
        $this->assertSame(100,$bot->fresh()->gem_qty);
    }
}
