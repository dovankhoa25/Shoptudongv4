<?php

namespace Tests\Feature;

use App\Models\{Bot, GemBot, GemTransaction, GoldTransaction, InventoryMovement, Server, ServerGameLogin, Transaction, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TradingAdminManualResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    public function test_manual_gem_completion_keeps_checkpoint_without_debiting_current_stock(): void
    {
        $server = Server::create(['name'=>'manual', 'status'=>true]);
        $customer = User::factory()->create(['balance'=>50000]);
        $bot = GemBot::create(['server_id'=>$server->id,'account_name'=>'test','account_password'=>'fake','gem_qty'=>200,'gift_ticket_qty'=>20,'consign_item_qty'=>4,
            'map_id'=>'84','area_number'=>'1','coordinates'=>'652-336']);
        $order = GemTransaction::create(['server_id'=>$server->id,'user_id'=>$customer->id,'bot_id'=>$bot->id,
            'character_name'=>'test','gem_qty'=>20,'amount_vnd'=>10000,'price_at_transaction'=>10,'status'=>'processing',
            'delivery_method'=>'gift_ticket','delivery_phase'=>'gifting','delivery_data'=>['claim'=>['marker'=>'original'],'last_step_id'=>'saved']]);
        $this->patch("/admin/gem-orders/{$order->id}/status",['action'=>'complete','note'=>'Verified in game'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('completed',$order->status);
        $this->assertSame('manual_completed',$order->delivery_phase);
        $this->assertSame('original',$order->delivery_data['claim']['marker']);
        $this->assertSame('gifting',$order->delivery_data['manual_resolution']['previous_phase']);
        $this->assertSame('saved',$order->delivery_data['last_step_id']);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(200,(int)$bot->fresh()->gem_qty);
        $this->assertSame(0,InventoryMovement::count());
        $this->assertSame(50000,(int)$customer->fresh()->balance);
    }

    public function test_manual_cancelled_gem_can_be_refunded_but_cannot_be_reopened_with_same_id(): void
    {
        $server = Server::create(['name'=>'manual', 'status'=>true]);
        $customer = User::factory()->create(['balance'=>50000]);
        $order = GemTransaction::create(['server_id'=>$server->id,'user_id'=>$customer->id,'character_name'=>'test',
            'gem_qty'=>20,'amount_vnd'=>10000,'price_at_transaction'=>10,'status'=>'processing','delivery_method'=>'consignment',
            'delivery_phase'=>'waiting_consignment','delivery_data'=>['claim'=>['marker'=>'original'],'delivery_item'=>['verified'=>true]]]);
        $this->patch("/admin/gem-orders/{$order->id}/status",['action'=>'cancel','cancel_reason'=>'Admin checked'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(50000,(int)$customer->fresh()->balance);
        $this->patch("/admin/gem-orders/{$order->id}/status",['action'=>'set_status','status'=>'pending'])
            ->assertRedirect()->assertSessionHasErrors('status');
        $this->post("/admin/gem-orders/{$order->id}/refund",['refund_reason'=>'Confirmed no delivery','refund_amount'=>10000])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('refunded',$order->fresh()->status);
        $this->assertTrue($order->fresh()->delivery_data['delivery_item']['verified']);
        $this->assertSame(60000,(int)$customer->fresh()->balance);
    }

    public function test_manual_gold_sale_and_import_completion_preserve_progress_and_credit_import_once(): void
    {
        $server = Server::create(['name'=>'manual', 'status'=>true]);
        $customer = User::factory()->create(['balance'=>50000]);
        $bot = Bot::create(['server_id'=>$server->id,'account_name'=>'test','account_password'=>'fake','type'=>'selling_main,import_main',
            'gold_qty'=>0,'gold_bar_qty'=>40,'nrj_gold_bar_qty'=>5,'map_id'=>'84','area_number'=>'1','coordinates'=>'652-336']);
        foreach(['order','import'] as $type) {
            $order = GoldTransaction::create(['server_id'=>$server->id,'user_id'=>$customer->id,'bot_id'=>$bot->id,
                'character_name'=>'test','type'=>$type,'gold_qty'=>37000000,'gold_bar_qty'=>1,'pure_gold_qty'=>0,
                'amount_vnd'=>10000,'price_at_transaction'=>10,'status'=>'processing','delivery_phase'=>'trading',
                'delivery_data'=>['claim'=>['marker'=>'original'],'progress'=>['bars'=>0,'pure'=>0]]]);
            $path=$type==='order'?"/admin/orders/{$order->id}/status":"/admin/imports/{$order->id}/status";
            $this->put($path,['status'=>'completed','note'=>'Verified in game'])->assertRedirect()->assertSessionHasNoErrors();
            $this->put($path,['status'=>'completed'])->assertRedirect()->assertSessionHasErrors('status');
            $this->assertSame('manual_completed',$order->fresh()->delivery_phase);
            $this->assertSame('original',$order->fresh()->delivery_data['claim']['marker']);
            $this->assertSame(0,$order->fresh()->delivery_data['progress']['bars']);
            if($type==='import')$this->assertSame(1,Transaction::where('idempotency_key',"gold-import-credit:{$order->id}")->count());
        }
        $this->assertSame(60000,(int)$customer->fresh()->balance);
        $this->assertSame(40,(int)$bot->fresh()->gold_bar_qty);
        $this->assertSame(5,(int)$bot->fresh()->nrj_gold_bar_qty);
        $this->assertSame(0,InventoryMovement::count());
    }

    public function test_admin_nrj_opening_balance_is_audited_and_configuration_update_preserves_it(): void
    {
        $server=Server::create(['name'=>'nrj', 'status'=>true]);
        $loginId=(int)\Illuminate\Support\Facades\DB::table('server_game_login')->max('id')+1;
        \Illuminate\Support\Facades\DB::table('server_game_login')->insert(['id'=>$loginId,'name'=>'test','ip'=>'test.invalid','port'=>'14445']);
        $config=['account_name'=>'nrj-test','account_password'=>'fake','type'=>'selling_main','server_id'=>$server->id,
            'server_game_id'=>$loginId,'map_id'=>'84','area_number'=>'1','coordinates'=>'652-336','status'=>true];
        $this->post('/admin/bots',[...$config,'gold_qty'=>0,'gold_bar_qty'=>2,'nrj_gold_bar_qty'=>3])->assertRedirect()->assertSessionHasNoErrors();
        $bot=Bot::where('account_name','nrj-test')->firstOrFail();
        $opening=InventoryMovement::where('idempotency_key',"opening:bot:{$bot->id}")->firstOrFail();
        $this->assertSame(2*37000000+3*195000000,(int)$opening->quantity_delta);
        $this->assertSame(3,(int)$bot->nrj_gold_bar_qty);
        $this->put("/admin/bots/{$bot->id}",[...$config,'name'=>'Changed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3,(int)$bot->fresh()->nrj_gold_bar_qty);
        $this->put("/admin/bots/{$bot->id}",[...$config,'nrj_gold_bar_qty'=>4])->assertRedirect()->assertSessionHasNoErrors();
        $movement=InventoryMovement::where('movement_type','manual_adjustment')->firstOrFail();
        $this->assertSame(195000000,(int)$movement->quantity_delta);
        $this->delete("/admin/bots/{$bot->id}")->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull($bot->fresh());
    }
}
