<?php

namespace Tests\Feature;

use App\Models\{Bot, GemBot, GemTransaction, GoldTransaction, InventoryMovement, Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;
use Tests\TestCase;

class GoldTransferReconciliationTest extends TestCase
{
    use RefreshDatabase;
    private string $secret = 'test-only-transfer-reconcile-secret-32-characters';
    private Server $server;
    private int $loginId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled', true);
        config()->set('trading_v3.hmac_secret', $this->secret);
        Event::fake();
        $this->server = Server::create(['name' => 'transfer-test', 'status' => true]);
        $this->loginId = (int)DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert(['id' => $this->loginId, 'name' => 'test-login', 'ip' => 'test.invalid', 'port' => '14445']);
    }

    private function signed(string $method, string $path, array $body = [])
    {
        $raw = $method === 'GET' ? '' : json_encode($body);
        $time = (string)time(); $nonce = bin2hex(random_bytes(16)); $tool = 'nro-trading-v3';
        $headers = ['Accept' => 'application/json', 'X-Tool-Id' => $tool, 'X-Tool-Timestamp' => $time, 'X-Tool-Nonce' => $nonce,
            'X-Tool-Signature' => hash_hmac('sha256', implode("\n", [$method, $path, $tool, $time, $nonce, hash('sha256', $raw)]), $this->secret)];
        return $method === 'GET' ? $this->get($path, $headers) : $this->json($method, $path, $body, $headers);
    }

    private function pair(int $template = 457, bool $claim = true): array
    {
        $base = ['server_id' => $this->server->id, 'server_game_id' => $this->loginId, 'account_password' => 'test-password',
            'gold_bar_qty' => 20, 'nrj_gold_bar_qty' => 20, 'gold_qty' => 1000, 'status' => true,
            'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '1', 'coordinates' => '652-336'];
        $from = Bot::create([...$base, 'name' => 'source', 'account_name' => 'test-source-'.Str::uuid(), 'type' => 'selling_sub']);
        $to = Bot::create([...$base, 'name' => 'destination', 'account_name' => 'test-dest-'.Str::uuid(), 'type' => 'selling_main']);
        $id = (string)Str::uuid();
        $body = ['action' => 'claim', 'from_bot_id' => $from->id, 'to_bot_id' => $to->id, 'bars_qty' => 2,
            'pure_gold_qty' => 0, 'gold_bar_template_id' => $template];
        if ($claim) $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertOk();
        return [$id, $from, $to, $body];
    }

    private function receipt(array $pair): array
    {
        [$id, $from, $to, $body] = $pair;
        $field = $body['gold_bar_template_id'] === 2032 ? 'nrj_gold_bar_qty' : 'gold_bar_qty';
        $snap = ['stock_version' => 1, 'gold_qty' => 1000, 'gold_bar_qty' => 20, 'nrj_gold_bar_qty' => 20];
        return [...$body, 'action' => 'receipt', 'step_id' => (string)Str::uuid(),
            'from_snapshot' => [...$snap, $field => 18], 'to_snapshot' => [...$snap, $field => 22],
            'evidence' => ['kind' => 'transfer', 'confirmed' => true, 'fresh_login' => true]];
    }

    private function failure(array $pair): array
    {
        [$id, $from, $to, $body] = $pair; $step = (string)Str::uuid();
        $intent = ['id' => $step, 'transfer_id' => $id, 'server_id' => $this->server->id,
            'from_bot_id' => $from->id, 'to_bot_id' => $to->id, 'gold_bar_template_id' => $body['gold_bar_template_id'],
            'bars_qty' => 2, 'pure_gold_qty' => 0, 'from_character_id' => 101, 'to_character_id' => 202,
            'from_trade_session_id' => (string)Str::uuid(), 'to_trade_session_id' => (string)Str::uuid(),
            'from_final_prepared' => true, 'to_final_prepared' => true];
        foreach (['from' => $from, 'to' => $to] as $side => $bot) {
            $intent[$side.'_binding'] = strtoupper(hash('sha256', $this->server->id.':test.invalid:14445:'.strtolower($bot->account_name)));
        }
        $intent['from_game_failed'] = ['intent_id' => $step, 'session_id' => $intent['from_trade_session_id'],
            'character_id' => 101, 'partner_id' => 202, 'gold_bar_template_id' => $body['gold_bar_template_id'],
            'bars_qty' => 2, 'pure_gold_qty' => 0, 'offer_verified' => true, 'outcome' => 'failed', 'observed_at' => now()->toIso8601String()];
        return [...$body, 'action' => 'cancel', 'server_id' => $this->server->id, 'step_id' => $step,
            'evidence' => ['kind' => 'transfer', 'confirmed' => true, 'unchanged' => true, 'intent' => $intent]];
    }

    private function reservation(string $id): InventoryMovement
    {
        return InventoryMovement::where('idempotency_key', 'trading-v3:gold-transfer:'.$id)->firstOrFail();
    }

    private function localReceipt(array $pair, bool $gameSuccess = true): array
    {
        $body = $this->receipt($pair); $failure = $this->failure($pair); $intent = $failure['evidence']['intent'];
        $body['step_id'] = $intent['id']; $body['server_id'] = $this->server->id;
        if ($gameSuccess) $intent['from_game_success'] = [...$intent['from_game_failed'], 'outcome' => 'success'];
        unset($intent['from_game_failed']);
        $intent['local_transfer'] = true; $intent['from_roles'] = $pair[1]->type; $intent['to_roles'] = $pair[2]->type;
        $intent['from_bars'] = 20; $intent['to_bars'] = 20;
        $body['evidence']['intent'] = $intent;
        return $body;
    }

    public function test_local_transfers_need_no_claim_and_reverse_receipt_can_arrive_first(): void
    {
        foreach ([457, 2032] as $template) {
            $pair = $this->pair($template, false); [$id, $from, $to] = $pair;
            $first = $this->localReceipt($pair); // Game already moved 2, ACK is queued locally.
            $to->update(['type' => 'import_main']); $from->update(['type' => 'import_sub']);
            $returnId = (string)Str::uuid(); $terms = [...$pair[3], 'from_bot_id' => $to->id, 'to_bot_id' => $from->id];
            $second = $this->localReceipt([$returnId, $to, $from, $terms], false);
            $field = $template === 2032 ? 'nrj_gold_bar_qty' : 'gold_bar_qty';
            $second['evidence']['intent']['from_bars'] = 22; $second['evidence']['intent']['to_bars'] = 18;
            $second['from_snapshot'][$field] = 20; $second['to_snapshot'][$field] = 20;
            $second['from_snapshot']['stock_version'] = 2; $second['to_snapshot']['stock_version'] = 2;
            $second['from_snapshot']['gold_qty'] = 3; $second['to_snapshot']['gold_qty'] = 4; // Boss theft is unrelated.
            $this->signed('PUT', '/app/v3/gold-transfers/'.$returnId, $second)->assertOk()->assertJsonPath('data.status', 'completed');
            $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $first)->assertOk()->assertJsonPath('data.status', 'completed');
            $count = InventoryMovement::count();
            $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $first)->assertOk();
            $this->signed('PUT', '/app/v3/gold-transfers/'.$returnId, $second)->assertOk();
            $this->assertDatabaseCount('inventory_movements', $count);
            $this->assertSame(20, (int)$from->refresh()->{$field}); $this->assertSame(20, (int)$to->refresh()->{$field});
            $this->assertSame(2, $from->stock_version); $this->assertSame(4, $from->gold_qty); $this->assertSame(3, $to->gold_qty);
            $this->assertSame(0, InventoryMovement::where('bot_id', $from->id)->whereIn('movement_type', ['transfer_in', 'transfer_out'])->sum('quantity_delta'));
        }
    }

    public function test_local_receipt_rejects_missing_or_mismatched_exact_game_proof(): void
    {
        $pair = $this->pair(457, false); [$id] = $pair; $base = $this->localReceipt($pair);
        foreach ([['evidence.intent.local_transfer', false], ['evidence.intent.from_bot_id', 99999],
            ['evidence.intent.from_binding', 'wrong'], ['evidence.intent.from_game_success.partner_id', 99999],
            ['evidence.intent.to_game_failed', ['outcome' => 'failed']]] as [$path, $value]) {
            $body = $base; data_set($body, $path, $value);
            $response = $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body);
            $this->assertContains($response->status(), [404, 422]);
            $this->assertDatabaseCount('inventory_movements', 0);
        }
        $body = $this->localReceipt($pair, false); $body['to_snapshot']['gold_bar_qty'] = 23;
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_gold_start_phase_release_and_stock_do_not_wait_for_other_web_operations(): void
    {
        [$id, $aux, $main] = $this->pair(); // A historical claimed reservation remains unacknowledged.
        $main->forceFill(['gold_bar_qty' => 0, 'gold_qty' => 0])->save();
        $orders = [];
        foreach (['first', 'second'] as $name) {
            $order = GoldTransaction::create(['type' => 'order', 'user_id' => User::factory()->create()->id,
                'server_id' => $this->server->id, 'bot_id' => $main->id, 'character_name' => $name, 'gold_qty' => 37000000,
                'gold_bar_qty' => 1, 'pure_gold_qty' => 0, 'amount_vnd' => 10000, 'price_at_transaction' => 3700, 'status' => 'pending']);
            $this->signed('PUT', '/app/v3/gold-transactions/'.$order->id, ['action' => 'start', 'bot_id' => $main->id, 'character_id' => 1234])
                ->assertOk()->assertJsonPath('data.delivery_phase', 'waiting_customer'); $orders[] = $order;
        }
        $phase = ['action' => 'phase', 'bot_id' => $main->id, 'step_id' => (string)Str::uuid(), 'phase' => 'trading'];
        $this->signed('PUT', '/app/v3/gold-transactions/'.$orders[0]->id, $phase)->assertOk();
        $main->update(['status' => false]); // A delayed report of game progress must still be recorded.
        $release = ['action' => 'release_unstarted', 'bot_id' => $main->id, 'step_id' => (string)Str::uuid(), 'evidence' => ['confirmed' => true, 'no_effect' => true]];
        $this->signed('PUT', '/app/v3/gold-transactions/'.$orders[0]->id, $release)->assertOk()->assertJsonPath('data.status', 'pending');
        $stock = ['action' => 'stock', 'stock_version' => 10, 'gold_bar_qty' => 6, 'nrj_gold_bar_qty' => 20, 'gold_qty' => 9];
        $this->signed('PUT', '/app/v3/gold/bots/'.$main->id, $stock)->assertOk()->assertJsonPath('data.stock_snapshot_result', 'applied');
        $count = InventoryMovement::count();
        $this->signed('PUT', '/app/v3/gold/bots/'.$main->id, $stock)->assertOk()->assertJsonPath('data.stock_snapshot_result', 'already_current');
        $stock['stock_version'] = 9; $stock['gold_qty'] = 1000;
        $this->signed('PUT', '/app/v3/gold/bots/'.$main->id, $stock)->assertOk()->assertJsonPath('data.stock_snapshot_result', 'stale')->assertJsonPath('data.gold_qty', 9);
        $stock['stock_version'] = 10;
        $this->signed('PUT', '/app/v3/gold/bots/'.$main->id, $stock)->assertOk()->assertJsonPath('data.stock_snapshot_result', 'version_conflict');
        $this->assertDatabaseCount('inventory_movements', $count);
        $this->assertSame('claimed', $this->reservation($id)->meta['status']);
        $sale = ['action' => 'receipt', 'bot_id' => $main->id, 'step_id' => (string)Str::uuid(), 'character_id' => 1234,
            'bars_qty' => 1, 'pure_gold_qty' => 0, 'completed' => true,
            'bot_snapshot' => ['stock_version' => 5, 'gold_bar_qty' => 5, 'nrj_gold_bar_qty' => 20, 'gold_qty' => 100],
            'evidence' => ['kind' => 'sale', 'confirmed' => true]];
        $this->signed('PUT', '/app/v3/gold-transactions/'.$orders[1]->id, $sale)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(6, $main->refresh()->gold_bar_qty); $this->assertSame(9, $main->gold_qty);
    }

    public function test_gem_start_and_newer_stock_do_not_wait_for_unacknowledged_delivery(): void
    {
        $bot = GemBot::create(['name' => 'test-gem', 'account_name' => 'gem-fixture', 'account_password' => 'test-only',
            'server_id' => $this->server->id, 'server_game_id' => $this->loginId, 'gem_qty' => 0, 'gift_ticket_qty' => 0,
            'consign_item_qty' => 0, 'status' => true, 'map_id' => '84', 'map_name' => 'Siêu thị', 'area_number' => '1', 'coordinates' => '652-336']);
        foreach (['gift_ticket', 'consignment'] as $method) {
            $order = GemTransaction::create(['user_id' => User::factory()->create()->id, 'server_id' => $this->server->id,
                'bot_id' => $bot->id, 'character_name' => 'gem-customer', 'gem_qty' => 20, 'amount_vnd' => 10000,
                'price_at_transaction' => 20, 'delivery_method' => $method, 'status' => 'pending']);
            $this->signed('PUT', '/app/v3/gem-transactions/'.$order->id, ['action' => 'start', 'bot_id' => $bot->id])->assertOk();
            $this->signed('PUT', '/app/v3/gem-transactions/'.$order->id, ['action' => 'phase', 'bot_id' => $bot->id,
                'phase' => $method === 'gift_ticket' ? 'gifting' : 'giving_item', 'step_id' => (string)Str::uuid()])->assertOk();
        }
        $stock = ['action' => 'stock', 'stock_version' => 5, 'gem_qty' => 100, 'gift_ticket_qty' => 10, 'consign_item_qty' => 3];
        $this->signed('PUT', '/app/v3/gem/bots/'.$bot->id, $stock)->assertOk()->assertJsonPath('data.stock_snapshot_result', 'applied');
        $count = InventoryMovement::count();
        $this->signed('PUT', '/app/v3/gem/bots/'.$bot->id, $stock)->assertOk()->assertJsonPath('data.stock_snapshot_result', 'already_current');
        $stock['stock_version'] = 4; $stock['gem_qty'] = 200;
        $this->signed('PUT', '/app/v3/gem/bots/'.$bot->id, $stock)->assertOk()->assertJsonPath('data.stock_snapshot_result', 'stale')->assertJsonPath('data.gem_qty', 100);
        $this->assertDatabaseCount('inventory_movements', $count);
    }

    public function test_nrj_and_radar_partial_round_can_park_and_resume_without_stock_upload(): void
    {
        foreach ([2032, 281] as $template) {
            [, , $bot] = $this->pair(2032, false);
            $field = \App\Services\GoldBarAsset::field($template);
            if ($template === 281) {
                DB::table('server_game_login')->updateOrInsert(['id'=>16], ['name'=>'test-super','ip'=>'dragonsuper.teamobi.com','port'=>'17001']);
                $this->server->forceFill(['gold_radar_enabled'=>true])->save();
                $bot->forceFill(['server_game_id'=>16,'radar_qty'=>2,'bag_capacity'=>20,'bag_free_slots'=>18])->save();
            } else $bot->update(['nrj_gold_bar_qty'=>2]);
            $order = GoldTransaction::create(['type'=>'order','user_id'=>User::factory()->create()->id,
                'server_id'=>$this->server->id,'character_name'=>'round-customer','gold_bar_template_id'=>$template,
                'gold_bar_qty'=>2,'pure_gold_qty'=>0,'gold_qty'=>2*\App\Services\GoldBarAsset::value($template),
                'amount_vnd'=>10000,'price_at_transaction'=>1000,'status'=>'pending']);
            $path='/app/v3/gold-transactions/'.$order->id;
            $base=['bot_id'=>$bot->id,'gold_bar_template_id'=>$template,'character_id'=>1234];
            $this->signed('PUT',$path,[...$base,'action'=>'start'])->assertOk();
            $step=(string)Str::uuid();$session=(string)Str::uuid();
            $snapshot=[...$bot->only(['gold_qty','gold_bar_qty','nrj_gold_bar_qty']), 'stock_version'=>2];
            if($template===281)$snapshot=[...$snapshot,'radar_qty'=>2,'bag_capacity'=>20,'bag_free_slots'=>18];
            $first=[...$base,'action'=>'receipt','step_id'=>$step,'bars_qty'=>1,'pure_gold_qty'=>0,'completed'=>false,
                'bot_snapshot'=>$snapshot,'evidence'=>['kind'=>'sale','confirmed'=>true,'intent'=>[
                    'id'=>$step,'order_id'=>$order->id,'trade_session_id'=>$session,'character_id'=>999,'customer_id'=>1234,
                    'gold_bar_template_id'=>$template,'stock_snapshot_pending'=>true,
                    'game_success'=>['intent_id'=>$step,'session_id'=>$session,'character_id'=>999,'partner_id'=>1234,
                        'gold_bar_template_id'=>$template,'bars_qty'=>1,'pure_gold_qty'=>0,'offer_verified'=>true,
                        'outcome'=>'success','observed_at'=>now()->toIso8601String()]]]];
            $this->signed('PUT',$path,$first)->assertOk()->assertJsonPath('data.delivery_data.progress.bars',1);
            $this->assertSame(2,(int)$bot->refresh()->{$field});
            $this->signed('PUT',$path,[...$base,'action'=>'phase','phase'=>'waiting_stock','step_id'=>(string)Str::uuid(),
                'evidence'=>['confirmed'=>true,'no_effect'=>true]])->assertOk()->assertJsonPath('data.delivery_data.stock_transfer_settled',true);
            $this->signed('PUT',$path,[...$base,'action'=>'release_unstarted','step_id'=>(string)Str::uuid(),
                'evidence'=>['confirmed'=>true,'no_effect'=>true]])->assertConflict();
            $this->signed('PUT',$path,[...$base,'action'=>'start'])->assertOk()->assertJsonPath('data.delivery_phase','waiting_customer');
            $last=[...$first,'step_id'=>(string)Str::uuid(),'completed'=>true,
                'bot_snapshot'=>[...$snapshot,'stock_version'=>3,$field=>0,'gold_qty'=>7],
                'evidence'=>['kind'=>'sale','confirmed'=>true]];
            if($template===281)$last['bot_snapshot']['bag_free_slots']=20;
            $this->signed('PUT',$path,$last)->assertOk()->assertJsonPath('data.status','completed');
            $count=InventoryMovement::count();
            $this->signed('PUT',$path,$first)->assertOk();$this->signed('PUT',$path,$last)->assertOk();
            $this->assertDatabaseCount('inventory_movements',$count);
            $this->assertSame(0,(int)$bot->refresh()->{$field});$this->assertSame(7,$bot->gold_qty);
            $this->assertSame(0,InventoryMovement::where('bot_id',$bot->id)->where('movement_type','sync_correction')->whereNull('idempotency_key')->count());
            $this->assertSame(-2*\App\Services\GoldBarAsset::value($template),(int)InventoryMovement::where('bot_id',$bot->id)->where('movement_type','sale')->sum('quantity_delta'));
        }
    }

    public function test_local_marker_does_not_bypass_radar_endpoint_restrictions(): void
    {
        $pair=$this->pair(281,false);[$id,$from,$to]=$pair;$body=$this->localReceipt($pair);
        foreach(['from_snapshot','to_snapshot'] as $key)$body[$key]=[...$body[$key],
            'radar_qty'=>20,'bag_capacity'=>40,'bag_free_slots'=>20];
        $this->signed('PUT','/app/v3/gold-transfers/'.$id,$body)->assertUnprocessable();
        foreach([16=>['dragonsuper.teamobi.com',17001],17=>['dragonsuper3.teamobi.com',17001]] as $login=>[$host,$port])
            DB::table('server_game_login')->updateOrInsert(['id'=>$login],['name'=>'test-super','ip'=>$host,'port'=>(string)$port]);
        $from->update(['server_game_id'=>16]);$to->update(['server_game_id'=>17]);
        $this->signed('PUT','/app/v3/gold-transfers/'.$id,$body)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements',0);
    }

    public function test_single_transfer_read_requires_hmac_and_does_not_mutate_pending_plan(): void
    {
        [$id, $from, $to] = $this->pair(); $before = $this->reservation($id)->getAttributes();
        $this->getJson('/app/v3/gold-transfers/'.$id)->assertUnauthorized();
        $this->signed('GET', '/app/v3/gold-transfers/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.server_id', $this->server->id)
            ->assertJsonPath('data.from_bot_id', $from->id)->assertJsonPath('data.to_bot_id', $to->id)
            ->assertJsonPath('data.status', 'claimed')->assertJsonPath('data.replay_request', null);
        $this->signed('GET', '/app/v3/gold-transfers/'.Str::uuid())->assertNotFound();
        $this->assertSame($before, $this->reservation($id)->getAttributes());
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_terminal_receipt_read_returns_exact_financial_projection_without_credentials(): void
    {
        $pair = $this->pair(2032); [$id, $from, $to] = $pair; $body = $this->receipt($pair);
        $body['worker_id'] = (string)Str::uuid(); $body['processing_token'] = (string)Str::uuid();
        $body['evidence']['intent'] = ['id' => $body['step_id'], 'transfer_id' => $id, 'account_password' => 'never-return-this'];
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertOk();
        $count = InventoryMovement::count(); $before = $this->reservation($id)->getAttributes();
        $expected = $body; unset($expected['worker_id'], $expected['processing_token'], $expected['evidence']['intent']['account_password']);
        $expected['server_id'] = $this->server->id;
        $result = $this->signed('GET', '/app/v3/gold-transfers/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.last_action', 'receipt')->assertJsonPath('data.last_step_id', $body['step_id']);
        $this->assertEquals($expected, $result->json('data.replay_request'));
        $this->assertStringNotContainsString('never-return-this', $result->getContent());
        $this->assertStringNotContainsString('processing_token', $result->getContent());
        $wrong = $body; $wrong['to_snapshot']['nrj_gold_bar_qty'] = 23;
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $wrong)->assertConflict();
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertOk();
        $this->assertSame($before, $this->reservation($id)->getAttributes());
        $this->assertDatabaseCount('inventory_movements', $count);
        $this->assertSame(18, $from->refresh()->nrj_gold_bar_qty); $this->assertSame(22, $to->refresh()->nrj_gold_bar_qty);
    }

    public function test_old_completed_ack_is_reconstructed_only_from_both_matching_receipt_ledgers(): void
    {
        $pair = $this->pair(); [$id] = $pair; $body = $this->receipt($pair);
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertOk();
        $row = $this->reservation($id); $meta = $row->meta; unset($meta['acknowledged_request']); $row->update(['meta' => $meta]);
        $expected = [...$body, 'server_id' => $this->server->id]; $count = InventoryMovement::count();
        $this->assertEquals($expected, $this->signed('GET', '/app/v3/gold-transfers/'.$id)->assertOk()->json('data.replay_request'));
        $side = InventoryMovement::where('idempotency_key', 'trading-v3:gold-transfer:'.$id.':in')->firstOrFail();
        $sideMeta = $side->meta; $sideMeta['payload_hash'] = str_repeat('0', 64); $side->update(['meta' => $sideMeta]);
        $this->signed('GET', '/app/v3/gold-transfers/'.$id)->assertOk()->assertJsonPath('data.replay_request', null);
        $this->assertDatabaseCount('inventory_movements', $count);
    }

    public function test_unchanged_cancel_ack_is_readable_but_old_cancellation_proof_is_not_invented(): void
    {
        $pair = $this->pair(); [$id, , , $body] = $pair;
        $body = [...$body, 'action' => 'cancel', 'step_id' => (string)Str::uuid(), 'evidence' => ['confirmed' => true, 'unchanged' => true]];
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertOk();
        $this->assertEquals([...$body, 'server_id' => $this->server->id], $this->signed('GET', '/app/v3/gold-transfers/'.$id)->assertOk()->json('data.replay_request'));
        $row = $this->reservation($id); $meta = $row->meta; unset($meta['acknowledged_request']); $row->update(['meta' => $meta]);
        $this->signed('GET', '/app/v3/gold-transfers/'.$id)->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.replay_request', null);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_final_failure_releases_only_reservation_despite_later_stock_changes_for_both_assets(): void
    {
        foreach ([457, 2032] as $template) {
            $pair = $this->pair($template); [$id, $from, $to] = $pair; $body = $this->failure($pair);
            if ($template === 2032) {
                $intent = &$body['evidence']['intent'];
                $intent['to_game_failed'] = [...$intent['from_game_failed'], 'session_id' => $intent['to_trade_session_id'], 'character_id' => 202, 'partner_id' => 101];
                unset($intent['from_game_failed']); unset($intent);
            }
            $from->forceFill(['stock_version' => 8, 'gold_qty' => 800, 'gold_bar_qty' => 30, 'nrj_gold_bar_qty' => 31])->save();
            $to->forceFill(['stock_version' => 9, 'gold_qty' => 700, 'gold_bar_qty' => 35, 'nrj_gold_bar_qty' => 36])->save();
            $beforeFrom = $from->refresh()->getAttributes(); $beforeTo = $to->refresh()->getAttributes(); $count = InventoryMovement::count();
            $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertOk()->assertJsonPath('data.status', 'cancelled');
            $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertOk();
            $this->assertSame($beforeFrom, $from->refresh()->getAttributes()); $this->assertSame($beforeTo, $to->refresh()->getAttributes());
            $this->assertDatabaseCount('inventory_movements', $count);
            $this->assertEquals($body, $this->signed('GET', '/app/v3/gold-transfers/'.$id)->assertOk()->json('data.replay_request'));
        }
    }

    public function test_failure_bypass_rejects_mismatched_proof_and_success_conflict(): void
    {
        $pair = $this->pair(); [$id, $from] = $pair; $base = $this->failure($pair);
        $from->forceFill(['stock_version' => 2])->save();
        $variants = [
            ['evidence.intent.transfer_id', (string)Str::uuid()], ['evidence.intent.id', (string)Str::uuid()],
            ['evidence.intent.server_id', 99999], ['evidence.intent.from_bot_id', 99999], ['evidence.intent.to_bot_id', 99999],
            ['evidence.intent.from_binding', str_repeat('A', 64)], ['evidence.intent.to_binding', str_repeat('A', 64)],
            ['evidence.intent.gold_bar_template_id', 2032], ['evidence.intent.bars_qty', 3], ['evidence.intent.pure_gold_qty', 1],
            ['evidence.intent.from_final_prepared', false], ['evidence.intent.from_final_prepared', null],
            ['evidence.intent.from_game_failed.intent_id', (string)Str::uuid()],
            ['evidence.intent.from_game_failed.session_id', (string)Str::uuid()], ['evidence.intent.from_game_failed.character_id', 123],
            ['evidence.intent.from_game_failed.partner_id', 123], ['evidence.intent.from_game_failed.gold_bar_template_id', 2032],
            ['evidence.intent.from_game_failed.bars_qty', 3], ['evidence.intent.from_game_failed.pure_gold_qty', 1],
            ['evidence.intent.from_game_failed.outcome', 'cancelled'], ['evidence.intent.from_game_failed.offer_verified', false],
            ['evidence.intent.from_game_failed.observed_at', 'invalid-date'], ['evidence.intent.to_game_failed', ['outcome' => 'failed']],
            ['evidence.intent.from_game_success', ['outcome' => 'success']], ['evidence.intent.to_game_success', ['outcome' => 'success']],
        ];
        foreach ($variants as [$path, $value]) {
            $body = $base; data_set($body, $path, $value);
            $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertUnprocessable();
            $this->assertSame('claimed', $this->reservation($id)->meta['status'], $path);
        }
        $missing = $base; unset($missing['evidence']['intent']['from_final_prepared']);
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $missing)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 1); $this->assertSame(20, $from->refresh()->gold_bar_qty);
    }

    public function test_cancel_without_final_failure_keeps_original_stock_version_gate(): void
    {
        $pair = $this->pair(); [$id, $from, , $body] = $pair;
        $from->forceFill(['stock_version' => 2])->save();
        $body = [...$body, 'action' => 'cancel', 'step_id' => (string)Str::uuid(), 'evidence' => ['confirmed' => true, 'unchanged' => true]];
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertConflict();
        $this->assertSame('claimed', $this->reservation($id)->meta['status']); $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_pending_success_snapshot_cannot_override_correlated_failure(): void
    {
        $pair = $this->pair(); [$id] = $pair; $body = $this->receipt($pair); $failure = $this->failure($pair);
        $body['step_id'] = $failure['step_id']; $body['evidence']['intent'] = $failure['evidence']['intent'];
        $body['evidence']['intent']['stock_snapshot_pending'] = true;
        $body['evidence']['intent']['from_game_success'] = [...$failure['evidence']['intent']['from_game_failed'], 'outcome' => 'success'];
        $this->signed('PUT', '/app/v3/gold-transfers/'.$id, $body)->assertUnprocessable();
        $this->assertSame('claimed', $this->reservation($id)->meta['status']); $this->assertDatabaseCount('inventory_movements', 1);
    }
}
