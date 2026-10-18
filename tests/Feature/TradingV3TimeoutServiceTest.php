<?php
namespace Tests\Feature;

use App\Models\{GemTransaction,GoldTransaction,Server,Transaction,User};
use App\Services\{TransactionService};
use App\Services\TradingV3\OrderTimeoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Event};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TradingV3TimeoutServiceTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp();Event::fake();$this->freezeTime(); }
    private function order(array $extra=[]): GoldTransaction {
        $o=GoldTransaction::create(array_replace(['type'=>'order','user_id'=>User::factory()->create(['balance'=>50000])->id,
            'server_id'=>Server::create(['name'=>'timeout-test','status'=>true])->id,'character_name'=>'recipient',
            'amount_vnd'=>11429,'gold_qty'=>37000000,'gold_bar_qty'=>1,'pure_gold_qty'=>0,'price_at_transaction'=>1000,
            'status'=>'pending','updated_by'=>'web'],$extra));
        $o->forceFill(['created_at'=>$extra['created_at']??now()->subSeconds(600)])->save();
        return $o;
    }
    private function refund(GoldTransaction $order,int $seconds=600,string $type='gold'): bool {
        return DB::transaction(fn()=>app(OrderTimeoutService::class)->refundPendingLocked(
            GoldTransaction::whereKey($order->id)->lockForUpdate()->firstOrFail(),$seconds,$type));
    }
    public function test_gold_exact_second_deadline_is_atomic_immediate_and_shared_with_cron(): void {
        config(['trading.gold_order_pending_timeout_minutes'=>11,'trading.gold_order_refund_grace_minutes'=>5]);
        $o=$this->order(['created_at'=>now()->subSeconds(599)]);
        $this->assertFalse($this->refund($o));$this->assertSame('pending',$o->fresh()->status);$this->assertNull($o->fresh()->cancel_requested_at);
        $this->travel(1)->seconds();$this->assertTrue($this->refund($o));
        $this->assertSame('cancelled',$o->fresh()->status);$this->assertNotNull($o->fresh()->refunded_at);
        $this->assertSame(0,$o->fresh()->delivery_data['timeout_policy']['refund_grace_minutes']);
        $this->assertSame(61429,(int)$o->user->fresh()->balance);$this->assertFalse($this->refund($o));
        $this->travel(20)->minutes();$this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(61429,(int)$o->user->fresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());
        $this->assertDatabaseHas('transactions',['idempotency_key'=>"gold-order-timeout-refund:{$o->id}",'amount'=>11429,'type'=>Transaction::TYPE_GOLD_ORDER_REFUND]);
    }
    public function test_gold_imports_processing_and_any_receipt_checkpoint_are_never_refunded(): void {
        foreach([
            ['type'=>'import'],['status'=>'processing'],['delivery_data'=>['claim'=>['marker'=>'checkpoint']]],
            ['delivery_data'=>['progress'=>['bars'=>1,'pure'=>0]]],
            ['delivery_data'=>['progress'=>['bars'=>0,'pure'=>1]]],
            ['delivery_data'=>['progress'=>['bars'=>0,'pure'=>0],'last_step_id'=>(string)Str::uuid()]],
        ] as $extra) {
            $o=$this->order($extra);$this->assertFalse($this->refund($o));
            $this->assertNull($o->fresh()->cancel_requested_at);$this->assertNull($o->fresh()->refunded_at);
            $this->assertSame(50000,(int)$o->user->fresh()->balance);
        }
        $this->assertSame(0,Transaction::where('type',Transaction::TYPE_GOLD_ORDER_REFUND)->count());
    }
    public function test_wrong_asset_type_or_invalid_seconds_cannot_mutate_gold_order(): void {
        $o=$this->order();
        $this->assertFalse($this->refund($o,600,'gem'));$this->assertFalse($this->refund($o,600,'other'));
        $this->assertFalse($this->refund($o,0));$this->assertFalse($this->refund($o,86401));
        $this->assertSame('pending',$o->fresh()->status);$this->assertNull($o->fresh()->cancel_requested_at);
    }
    public function test_gold_wallet_overflow_rolls_back_cancel_before_retry(): void {
        $o=$this->order();$o->user->forceFill(['balance'=>TransactionService::MAX_BALANCE])->save();
        try {$this->refund($o);$this->fail('Expected wallet overflow to abort the entire refund.');}
        catch(ValidationException $e){$this->assertArrayHasKey('balance',$e->errors());}
        $this->assertSame('pending',$o->fresh()->status);$this->assertNull($o->fresh()->cancel_requested_at);$this->assertNull($o->fresh()->refunded_at);
        $this->assertSame(0,Transaction::where('idempotency_key',"gold-order-timeout-refund:{$o->id}")->count());
        $o->user->forceFill(['balance'=>50000])->save();$this->assertTrue($this->refund($o));
        $this->assertSame(61429,(int)$o->user->fresh()->balance);
    }
    public function test_gem_default_type_contract_and_terminal_status_are_unchanged(): void {
        $gold=$this->order();
        $gem=GemTransaction::create(['server_id'=>$gold->server_id,'user_id'=>$gold->user_id,'character_name'=>'recipient',
            'gem_qty'=>10,'amount_vnd'=>10000,'price_at_transaction'=>10,'status'=>'pending','delivery_method'=>'gift_ticket']);
        $gem->forceFill(['created_at'=>now()->subSeconds(600)])->save();
        $this->assertTrue(DB::transaction(fn()=>app(OrderTimeoutService::class)->refundPendingLocked(
            GemTransaction::whereKey($gem->id)->lockForUpdate()->firstOrFail(),600)));
        $this->assertSame('refunded',$gem->fresh()->status);$this->assertNotNull($gem->fresh()->refunded_at);
        $this->assertSame(60000,(int)$gem->user->fresh()->balance);
        $this->assertSame(1,Transaction::where('idempotency_key',"gem-order-timeout-refund:{$gem->id}")->count());
        $this->assertSame('pending',$gold->fresh()->status);
    }
}
