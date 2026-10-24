<?php
namespace Tests\Feature;

use App\Models\{Bot,GoldPrice,GoldTransaction,InventoryMovement,Server,User};
use App\Services\{GoldBarAsset,GoldBarOrderQuoteService};
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Event};
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class GoldNrjTradingTest extends TestCase
{
    use RefreshDatabase;
    private Server $server;
    private int $loginId;
    private string $secret='test-only-nrj-secret-at-least-32-characters';
    protected function setUp(): void {
        parent::setUp();config()->set('trading_v3',require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled',true);config()->set('trading_v3.hmac_secret',$this->secret);
        config()->set('services.app_api_key','nrj-legacy-test');Event::fake();
        $this->server=Server::create(['name'=>'nrj-test','status'=>true]);
        $this->loginId=(int)DB::table('server_game_login')->max('id')+1;
        DB::table('server_game_login')->insert(['id'=>$this->loginId,'name'=>'test-login','ip'=>'test.invalid','port'=>'14445']);
    }
    private function signed(string $method,string $path,array $data=[]) {
        $body=$method==='GET'?'':json_encode($data);$time=(string)time();$nonce=bin2hex(random_bytes(16));$tool='nro-trading-v3';
        $headers=['X-Tool-Id'=>$tool,'X-Tool-Timestamp'=>$time,'X-Tool-Nonce'=>$nonce,
            'X-Tool-Signature'=>hash_hmac('sha256',implode("\n",[$method,$path,$tool,$time,$nonce,hash('sha256',$body)]),$this->secret)];
        return $method==='GET'?$this->get($path,[...$headers,'Accept'=>'application/json']):$this->json($method,$path,$data,$headers);
    }
    private function bot(string $role='selling_main',int $standard=20,int $nrj=10): Bot {
        return Bot::create(['name'=>'nrj-test','account_name'=>'test-'.Str::uuid(),'account_password'=>'test-password',
            'type'=>$role,'server_id'=>$this->server->id,'server_game_id'=>$this->loginId,'gold_bar_qty'=>$standard,
            'nrj_gold_bar_qty'=>$nrj,'gold_qty'=>0,'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'1',
            'coordinates'=>'652-336','status'=>true]);
    }
    private function order(Bot $bot,int $asset=2032,string $type='order',int $bars=2): GoldTransaction {
        return GoldTransaction::create(['type'=>$type,'user_id'=>User::factory()->create()->id,'server_id'=>$bot->server_id,
            'character_name'=>'testcustomer','gold_bar_template_id'=>$asset,'gold_bar_qty'=>$bars,'pure_gold_qty'=>0,
            'gold_qty'=>$bars*GoldBarAsset::value($asset),'amount_vnd'=>10000,'price_at_transaction'=>1000,
            'status'=>'pending','updated_by'=>'web']);
    }
    private function start(Bot $bot,GoldTransaction $o): void {
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['action'=>'start','bot_id'=>$bot->id,
            'gold_bar_template_id'=>$o->gold_bar_template_id,'character_id'=>1234])->assertOk();
    }
    private function receipt(Bot $bot,GoldTransaction $o): array {
        $asset=$o->gold_bar_template_id;$sign=$o->type==='import'?1:-1;
        return ['action'=>'receipt','bot_id'=>$bot->id,'gold_bar_template_id'=>$asset,'character_id'=>1234,
            'step_id'=>(string)Str::uuid(),'bars_qty'=>$o->gold_bar_qty,'pure_gold_qty'=>0,'completed'=>true,
            'bot_snapshot'=>['stock_version'=>$bot->stock_version+1,'gold_qty'=>0,
                'gold_bar_qty'=>$bot->gold_bar_qty+($asset===457?$sign*$o->gold_bar_qty:0),
                'nrj_gold_bar_qty'=>$bot->nrj_gold_bar_qty+($asset===2032?$sign*$o->gold_bar_qty:0)],
            'evidence'=>['kind'=>$o->type==='import'?'import':'sale','confirmed'=>true]];
    }
    public function test_public_buy_and_import_use_selected_asset_with_shared_rate(): void {
        GoldPrice::create(['server_id'=>$this->server->id,'price'=>3700,'import_price'=>1000,'status'=>true]);
        $user=User::factory()->create(['balance'=>200000]);Passport::actingAs($user,['profile:read','profile:write']);
        $this->postJson('/api/orders',['server_id'=>$this->server->id,'character_name'=>'hero','money_amount'=>100000,
            'gold_type'=>'bar','gold_bar_template_id'=>2032])->assertCreated()
            ->assertJsonPath('data.gold_bar_template_id',2032)->assertJsonPath('data.gold_per_bar',195000000)
            ->assertJsonPath('data.gold_bars',1)->assertJsonPath('data.charged_amount',52703)->assertJsonPath('data.actual_gold',195000000);
        $this->assertSame(147297,(int)$user->refresh()->balance);
        $this->assertDatabaseHas('gold_transactions',['type'=>'order','gold_bar_template_id'=>2032,'gold_qty'=>195000000,'gold_bar_qty'=>1,'amount_vnd'=>52703]);
        $this->postJson('/api/imports',['server_id'=>$this->server->id,'character_name'=>'hero','gold_bar_qty'=>2,
            'gold_bar_template_id'=>2032])->assertCreated()->assertJsonPath('data.gold_bar_template_id',2032)
            ->assertJsonPath('data.gold_per_bar',195000000);
        $this->assertDatabaseHas('gold_transactions',['type'=>'import','gold_bar_template_id'=>2032,'gold_qty'=>390000000,'amount_vnd'=>390000]);
        $this->assertSame(147297,(int)$user->refresh()->balance,'Creating an import must not credit its wallet.');
    }
    public function test_shared_rate_quote_floor_charge_ceiling_and_legacy_default(): void {
        $quotes=new GoldBarOrderQuoteService;
        $nrj=$quotes->quote(100000,3700,2032);$standard=$quotes->quote(100000,3700);
        $this->assertSame(1,$nrj['gold_bar_qty']);$this->assertSame(52703,$nrj['charged_amount']);
        $this->assertSame(10,$standard['gold_bar_qty']);$this->assertSame(457,$standard['gold_bar_template_id']);
        $this->assertSame(0,$quotes->quote(52702,3700,2032)['gold_bar_qty']);
        $this->assertSame(1,$quotes->quote(52703,3700,2032)['gold_bar_qty']);
    }
    public function test_nrj_sale_reserves_its_own_stock_and_exact_receipt_is_idempotent(): void {
        $bot=$this->bot();$o=$this->order($bot);$this->start($bot,$o);
        $this->signed('GET','/app/v3/gold/bots')->assertOk()->assertJsonPath('data.0.available.bars',20)
            ->assertJsonPath('data.0.nrj_available.bars',8)->assertJsonPath('data.0.receive_room',979)
            ->assertJsonPath('data.0.nrj_receive_room',989);
        $payload=$this->receipt($bot,$o);$wrong=$payload;$wrong['gold_bar_template_id']=457;
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertConflict();
        $missing=$payload;unset($missing['bot_snapshot']['nrj_gold_bar_qty']);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$missing)->assertUnprocessable();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$payload)->assertOk()->assertJsonPath('data.status','completed');
        $count=InventoryMovement::count();$this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$payload)->assertOk();
        $this->assertSame($count,InventoryMovement::count());$this->assertSame(20,$bot->refresh()->gold_bar_qty);$this->assertSame(8,$bot->nrj_gold_bar_qty);
        $ledger=InventoryMovement::where('movement_type','sale')->sole();
        $this->assertSame(-390000000,(int)$ledger->quantity_delta);$this->assertSame(2032,$ledger->meta['gold_bar_template_id']);
    }
    public function test_standard_stock_does_not_fulfil_nrj_and_each_capacity_is_independent(): void {
        $bot=$this->bot('selling_main,import_main',999,0);$o=$this->order($bot);$this->start($bot,$o);
        $this->assertSame('waiting_stock',$o->refresh()->delivery_phase);
        $import=$this->order($bot,2032,'import',999);$this->start($bot,$import);
        $this->assertSame('waiting_customer',$import->refresh()->delivery_phase);
        $this->signed('GET','/app/v3/gold/bots')->assertOk()->assertJsonPath('data.0.nrj_receive_room',0)
            ->assertJsonPath('data.0.receive_room',0);
        $this->signed('PUT',"/app/v3/gold-transactions/{$import->id}",$this->receipt($bot,$import))->assertOk();
        $this->assertSame(999,$bot->refresh()->gold_bar_qty);$this->assertSame(999,$bot->nrj_gold_bar_qty);
    }
    public function test_legacy_standard_snapshot_preserves_nrj_stock_and_old_claim_resumes(): void {
        $bot=$this->bot();$o=$this->order($bot,457);$this->start($bot,$o);$o->refresh();
        $data=$o->delivery_data;unset($data['claim']['gold_bar_template_id']);$o->update(['delivery_data'=>$data]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['action'=>'start','bot_id'=>$bot->id])->assertOk();
        $payload=$this->receipt($bot,$o);unset($payload['gold_bar_template_id'],$payload['bot_snapshot']['nrj_gold_bar_qty']);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$payload)->assertOk();
        $this->assertSame(18,$bot->refresh()->gold_bar_qty);$this->assertSame(10,$bot->nrj_gold_bar_qty);
        $this->signed('PUT',"/app/v3/gold/bots/{$bot->id}",['action'=>'stock','stock_version'=>2,'gold_qty'=>0,'gold_bar_qty'=>17])->assertOk();
        $this->assertSame(10,$bot->refresh()->nrj_gold_bar_qty);
    }
    public function test_nrj_import_credits_frozen_amount_once_and_rejects_other_asset(): void {
        $bot=$this->bot('import_main');$o=$this->order($bot,2032,'import');$o->update(['amount_vnd'=>390000]);
        $user=User::findOrFail($o->user_id);$user->update(['balance'=>100]);$this->start($bot,$o);
        $payload=$this->receipt($bot,$o);$wrong=$payload;$wrong['gold_bar_template_id']=457;
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertConflict();
        $this->assertSame(100,(int)$user->refresh()->balance);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$payload)->assertOk();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$payload)->assertOk();
        $this->assertSame(390100,(int)$user->refresh()->balance);
        $this->assertDatabaseCount('transactions',1);$this->assertSame(12,$bot->refresh()->nrj_gold_bar_qty);
        $this->assertSame(20,$bot->gold_bar_qty);
    }
    public function test_nrj_transfer_is_bound_to_asset_and_uses_separate_999_room(): void {
        $from=$this->bot('selling_sub',5,3);$to=$this->bot('selling_main',999,997);$id=(string)Str::uuid();
        $claim=['action'=>'claim','from_bot_id'=>$from->id,'to_bot_id'=>$to->id,'gold_bar_template_id'=>2032,'bars_qty'=>2,'pure_gold_qty'=>0];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$claim)->assertOk()->assertJsonPath('data.gold_bar_template_id',2032);
        $this->assertSame(1,GoldTradingService::available($from,template:2032)['bars']);
        $this->assertSame(5,GoldTradingService::available($from)['bars']);
        $wrong=$claim;$wrong['gold_bar_template_id']=457;$this->signed('PUT',"/app/v3/gold-transfers/{$id}",$wrong)->assertConflict();
        $receipt=[...$claim,'action'=>'receipt','step_id'=>(string)Str::uuid(),
            'from_snapshot'=>['stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>5,'nrj_gold_bar_qty'=>1],
            'to_snapshot'=>['stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>999,'nrj_gold_bar_qty'=>999],
            'evidence'=>['kind'=>'transfer','confirmed'=>true]];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$receipt)->assertOk()->assertJsonPath('data.status','completed');
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$receipt)->assertOk();
        $this->assertSame(1,$from->refresh()->nrj_gold_bar_qty);$this->assertSame(999,$to->refresh()->nrj_gold_bar_qty);
        $this->assertSame(2,InventoryMovement::whereIn('movement_type',['transfer_in','transfer_out'])->count());
        $this->assertSame(0,(int)InventoryMovement::whereIn('movement_type',['transfer_in','transfer_out'])->sum('quantity_delta'));
    }
    public function test_nrj_transfer_cancel_retains_asset_and_frees_only_its_reservation(): void {
        $from=$this->bot('import_main',2,5);$to=$this->bot('import_sub',999,0);$id=(string)Str::uuid();
        $claim=['action'=>'claim','from_bot_id'=>$from->id,'to_bot_id'=>$to->id,'gold_bar_template_id'=>2032,'bars_qty'=>5,'pure_gold_qty'=>0];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$claim)->assertOk();
        $cancel=[...$claim,'action'=>'cancel','step_id'=>(string)Str::uuid(),'evidence'=>['confirmed'=>true,'unchanged'=>true]];
        $this->signed('PUT',"/app/v3/gold-transfers/{$id}",$cancel)->assertOk()->assertJsonPath('data.status','cancelled')->assertJsonPath('data.gold_bar_template_id',2032);
        $this->assertSame(5,GoldTradingService::available($from,template:2032)['bars']);
        $this->assertSame(999,GoldTradingService::receiveRoom($to,template:2032));
    }
    public function test_nrj_server_success_proof_is_asset_bound_and_preserves_unobserved_stock(): void {
        $bot=$this->bot();$o=$this->order($bot);$this->start($bot,$o);$payload=$this->receipt($bot,$o);
        $payload['bot_snapshot']['nrj_gold_bar_qty']=10;
        $proof=['intent_id'=>$payload['step_id'],'outcome'=>'success','observed_at'=>now()->toIso8601String(),
            'session_id'=>12,'offer_verified'=>true,'character_id'=>17,'partner_id'=>1234,'bars_qty'=>2,'pure_gold_qty'=>0,'gold_bar_template_id'=>2032];
        $payload['evidence']['intent']=['id'=>$payload['step_id'],'order_id'=>$o->id,'trade_session_id'=>12,
            'character_id'=>17,'customer_id'=>1234,'gold_bar_template_id'=>2032,'stock_snapshot_pending'=>true,'game_success'=>$proof];
        $wrong=$payload;$wrong['evidence']['intent']['game_success']['gold_bar_template_id']=457;
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$wrong)->assertUnprocessable();
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$payload)->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(10,$bot->refresh()->nrj_gold_bar_qty,'Do not fabricate a post-effect stock before its packet.');
        $this->assertSame(-390000000,(int)InventoryMovement::where('movement_type','sale')->sole()->quantity_delta);
        $this->signed('PUT',"/app/v3/gold/bots/{$bot->id}",['action'=>'stock','stock_version'=>2,'gold_qty'=>0,'gold_bar_qty'=>20,'nrj_gold_bar_qty'=>8])->assertOk();
        $this->assertSame(8,$bot->refresh()->nrj_gold_bar_qty);
    }
    public function test_nrj_receipt_with_concurrent_top_up_records_operation_and_correction_separately(): void {
        $bot=$this->bot();$o=$this->order($bot);$this->start($bot,$o);$payload=$this->receipt($bot,$o);
        $payload['bot_snapshot']['gold_bar_qty']=21;$payload['bot_snapshot']['nrj_gold_bar_qty']=13;
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",$payload)->assertOk()->assertJsonPath('data.status','completed');
        $sale=InventoryMovement::where('movement_type','sale')->sole();
        $this->assertSame(-390000000,(int)$sale->quantity_delta);
        $this->assertSame(1,$sale->meta['reconciliation_bars_delta']);$this->assertSame(5,$sale->meta['reconciliation_nrj_bars_delta']);
        $this->assertSame(13,$bot->refresh()->nrj_gold_bar_qty);$this->assertSame(21,$bot->gold_bar_qty);
    }
    public function test_unadopted_legacy_row_does_not_block_stock_refresh_or_new_customer(): void {
        $bot=$this->bot();$old=$this->order($bot,457);$old->update(['status'=>'processing','bot_id'=>$bot->id]);
        $this->signed('PUT',"/app/v3/gold/bots/{$bot->id}",['action'=>'stock','stock_version'=>1,'gold_qty'=>0,'gold_bar_qty'=>20,'nrj_gold_bar_qty'=>10])->assertOk();
        $new=$this->order($bot);$this->start($bot,$new);$this->assertSame('waiting_customer',$new->refresh()->delivery_phase);
        $this->assertNull($old->refresh()->delivery_data);
    }
    public function test_v2_cannot_list_or_mutate_nrj_order(): void {
        $bot=$this->bot();$standard=$this->order($bot,457);$nrj=$this->order($bot);$headers=['X-APP-KEY'=>'nrj-legacy-test'];
        $this->getJson('/app/v2/gold-transactions',$headers)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$standard->id);
        $this->putJson("/app/v2/gold-transactions/{$nrj->id}",['status'=>'completed','bot_id'=>$bot->id,'gold_bar_qty'=>20,'gold_qty'=>0],$headers)->assertConflict();
        $this->assertSame('pending',$nrj->refresh()->status);$this->assertSame(10,$bot->refresh()->nrj_gold_bar_qty);
    }
    public function test_legacy_processing_adoption_preserves_progress_and_does_not_block_other_customers(): void {
        $bot=$this->bot();$old=$this->order($bot,457);$old->update(['bot_id'=>$bot->id,'status'=>'processing','delivery_phase'=>'trading',
            'delivery_data'=>['progress'=>['bars'=>1,'pure'=>0],'legacy_note'=>'keep']]);
        $this->start($bot,$old);$old->refresh();$this->assertSame('waiting_customer',$old->delivery_phase);
        $this->assertSame(1,$old->delivery_data['progress']['bars']);$this->assertSame('keep',$old->delivery_data['legacy_note']);
        $this->assertTrue($old->delivery_data['legacy_resume']['requires_customer_invitation']);$this->assertDatabaseCount('inventory_movements',0);
        $next=$this->order($bot);$this->start($bot,$next);$this->assertSame('waiting_customer',$next->refresh()->delivery_phase);
        $this->assertSame(20,$bot->refresh()->gold_bar_qty);$this->assertSame(10,$bot->nrj_gold_bar_qty);
    }
    public function test_legacy_adoption_rejects_corrupt_progress_and_changed_checkpoint(): void {
        $bot=$this->bot();$o=$this->order($bot,457);$o->update(['status'=>'processing','bot_id'=>$bot->id,'delivery_data'=>['progress'=>['bars'=>3,'pure'=>0]]]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['action'=>'start','bot_id'=>$bot->id])->assertConflict();
        $o->update(['delivery_data'=>['claim'=>['old'=>'changed']]]);
        $this->signed('PUT',"/app/v3/gold-transactions/{$o->id}",['action'=>'start','bot_id'=>$bot->id])->assertConflict();
    }
}
