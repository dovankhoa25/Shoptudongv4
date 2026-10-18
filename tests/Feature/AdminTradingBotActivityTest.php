<?php

namespace Tests\Feature;

use App\Events\{TradingBotActivity, UserEvent};
use App\Models\{Bot, GemBot, GemTransaction, GoldTransaction, Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use Tests\TestCase;

class AdminTradingBotActivityTest extends TestCase
{
    use RefreshDatabase;

    private string $worker = '00000000-0000-4000-8000-000000000201';
    private string $otherWorker = '00000000-0000-4000-8000-000000000202';
    private string $secret = 'test-only-activity-secret-at-least-32-characters';
    private Server $server;
    private int $loginId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled', true);
        config()->set('trading_v3.hmac_secret', $this->secret);
        Event::fake();
        $this->freezeTime();
        $this->server = Server::create(['name' => 'activity-test', 'status' => true]);
        $this->loginId = (int) DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert(['id' => $this->loginId, 'name' => 'test-login', 'ip' => 'test.example', 'port' => '14445']);
    }

    private function bot(string $module, bool $status = true): GemBot|Bot
    {
        $data = [
            'name' => 'activity-bot', 'account_name' => 'account-private', 'account_password' => 'password-private',
            'server_id' => $this->server->id, 'server_game_id' => $this->loginId,
            'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41', 'coordinates' => '652-336',
            'proxy' => 'test.example:80:proxy-private:password-private', 'status' => $status,
        ];
        $bot = $module === 'gem'
            ? GemBot::create([...$data, 'gem_qty' => 100, 'gift_ticket_qty' => 10, 'consign_item_qty' => 3])
            : Bot::create([...$data, 'type' => 'selling_main', 'gold_qty' => 0, 'gold_bar_qty' => 10]);
        return $bot;
    }

    private function order(string $module): GemTransaction|GoldTransaction
    {
        $data = [
            'user_id' => User::factory()->create()->id, 'server_id' => $this->server->id,
            'character_name' => 'customer-private', 'amount_vnd' => 10000, 'price_at_transaction' => 10,
            'status' => 'pending', 'updated_by' => 'web', 'bot_id' => null,
        ];
        return $module === 'gem'
            ? GemTransaction::create([...$data, 'delivery_method' => 'consignment', 'gem_qty' => 30])
            : GoldTransaction::create([...$data, 'type' => 'order', 'gold_qty' => 37000000, 'gold_bar_qty' => 1, 'pure_gold_qty' => 0]);
    }

    private function activity(string $module, GemBot|Bot $bot, array $data = [])
    {
        $path = "/app/v3/{$module}/bots/{$bot->id}/activity";
        $data = ['worker_id' => $this->worker, 'code' => 'ready', ...$data];
        $body = json_encode($data);
        $time = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $tool = 'nro-trading-v3';
        $response = $this->postJson($path, $data, [
            'X-Tool-Id' => $tool, 'X-Tool-Timestamp' => $time, 'X-Tool-Nonce' => $nonce,
            'X-Tool-Signature' => hash_hmac('sha256', implode("\n", ['POST', $path, $tool, $time, $nonce, hash('sha256', $body)]), $this->secret),
        ]);
        return $response;
    }

    public function test_public_activity_is_transient_redacted_and_customer_notice_is_private_even_before_claim(): void
    {
        foreach (['gem', 'gold'] as $module) {
            $bot = $this->bot($module);
            $order = $this->order($module);
            $botBefore = $bot->refresh()->getAttributes();
            $orderBefore = $order->refresh()->getAttributes();
            Event::fake();
            $this->activity($module, $bot, [
                'code' => 'name_mismatch', 'map_id' => 84, 'zone' => 41, 'x' => 652, 'y' => 336,
                'online_seconds' => 60, 'wait_seconds' => 10,
                'order_id' => $order->id, 'customer_message' => 'customer-private #order-private',
                'text' => 'raw-private-text', 'account_password' => 'password-private', 'proxy' => 'proxy-private',
            ])->assertOk();
            $event = Event::dispatched(TradingBotActivity::class)->first()[0];
            $this->assertSame('Trading.Bots', $event->broadcastOn()[0]->name);
            $payload = $event->broadcastWith();
            $this->assertEqualsCanonicalizing([
                'bot_id', 'bot_type', 'server_id', 'code', 'map_id', 'zone', 'x', 'y',
                'online_seconds', 'wait_seconds', 'text', 'observed_at', 'expires_at',
            ], array_keys($payload));
            $this->assertSame($module === 'gem' ? 'gem_bot' : 'bot', $payload['bot_type']);
            $this->assertSame('Tên đặt đơn chưa khớp ingame', $payload['text']);
            $this->assertSame(now()->toIso8601String(), $payload['observed_at']);
            $this->assertSame(now()->addSeconds(35)->toIso8601String(), $payload['expires_at']);
            foreach (['account-private', 'password-private', 'proxy-private', 'customer-private', 'raw-private-text', $this->worker] as $private) {
                $this->assertStringNotContainsString($private, json_encode($payload));
            }
            $notice = Event::dispatched(UserEvent::class)->first()[0];
            $this->assertSame('private-User.'.$order->user_id, $notice->broadcastOn()->name);
            $this->assertSame($order->id, $notice->payload['order_id']);
            $this->assertSame('customer-private #order-private', $notice->message);
            $this->assertSame($payload['expires_at'], $notice->payload['expires_at']);
            $this->assertSame($botBefore, $bot->refresh()->getAttributes());
            $this->assertSame($orderBefore, $order->refresh()->getAttributes());
        }
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_activity_codes_describe_current_work_without_claiming_a_reconnect_is_needed(): void
    {
        $cases = [
            'gem' => [
                'giving_item' => 'Đang giao đồ ký gửi', 'buying' => 'Đang mua đồ ký gửi', 'gifting' => 'Đang tặng ngọc',
                'reconciling' => 'Đang đối chiếu tồn và giao dịch', 'review_required' => 'Đang kiểm tra kết quả giao dịch',
                'api_wait' => 'Đã xử lý trong game — đang chờ web xác nhận',
            ],
            'gold' => [
                'giving_bars' => 'Đang giao vàng cho khách', 'receiving_bars' => 'Đang nhận vàng từ khách',
                'storage' => 'Đang gom vàng vào bot phụ', 'refilling' => 'Đang bơm vàng cho bot bán',
                'reconciling' => 'Đang đối chiếu tồn và giao dịch', 'reserve_idle' => 'Bot phụ đang nghỉ',
            ],
        ];
        foreach ($cases as $module => $codes) {
            $bot = $this->bot($module);
            $before = $bot->refresh()->getAttributes();
            foreach ($codes as $code => $label) {
                Event::fake();
                $this->activity($module, $bot, ['code' => $code, 'online_seconds' => 120])->assertOk();
                Event::assertDispatched(TradingBotActivity::class, fn ($event) => $event->activity['code'] === $code && $event->activity['text'] === $label);
                Event::assertNotDispatched(UserEvent::class);
            }
            $this->assertSame($before, $bot->refresh()->getAttributes());
        }
    }

    public function test_invalid_customer_notice_is_rejected_before_any_broadcast(): void
    {
        foreach (['gem', 'gold'] as $module) {
            $bot = $this->bot($module);
            $other = $this->bot($module);
            $order = $this->order($module);
            $otherServer = Server::create(['name' => 'other-activity-server', 'status' => true]);
            $cases = [
                ['server_id' => $otherServer->id, 'bot_id' => null, 'status' => 'pending'],
                ['server_id' => $bot->server_id, 'bot_id' => $other->id, 'status' => 'pending'],
            ];
            foreach ($cases as $values) {
                $order->forceFill($values)->save();
                Event::fake();
                $this->activity($module, $bot, ['code' => 'name_mismatch', 'order_id' => $order->id, 'customer_message' => 'private'])->assertUnprocessable();
                Event::assertNotDispatched(TradingBotActivity::class);
                Event::assertNotDispatched(UserEvent::class);
            }
        }
    }

    public function test_owned_completed_notice_and_disabled_activity_keep_web_status_and_orders_unchanged(): void
    {
        foreach (['gem', 'gold'] as $module) {
            $bot = $this->bot($module, false);
            $order = $this->order($module);
            $order->forceFill(['status' => 'completed', 'bot_id' => $bot->id])->save();
            $before = $order->refresh()->getAttributes();
            Event::fake();
            $this->activity($module, $bot, ['code' => 'disabled', 'online_seconds' => 0, 'order_id' => $order->id, 'customer_message' => 'thank-you'])->assertOk();
            Event::assertDispatched(TradingBotActivity::class, fn ($event) => $event->activity['code'] === 'disabled' && $event->activity['online_seconds'] === 0);
            Event::assertDispatched(UserEvent::class);
            $this->assertFalse($bot->refresh()->status);
            $this->assertSame($before, $order->refresh()->getAttributes());
            Event::fake();
            $this->activity($module, $bot, ['worker_id' => $this->otherWorker])->assertOk();
            $this->assertFalse($bot->refresh()->status);
            $this->assertSame($before, $order->refresh()->getAttributes());
            Event::fake();
            $this->activity($module, $bot, ['code' => 'unknown-code'])->assertUnprocessable();
            Event::assertNotDispatched(TradingBotActivity::class);
        }
    }
    public function test_same_bot_private_notice_preserves_orders_with_or_without_delivery_checkpoint(): void
    {
        foreach (['gem','gold'] as $module) {
            $bot=$this->bot($module);$order=$this->order($module);
            $claim=$module==='gem'?['character_name'=>$order->character_name,'gem_qty'=>(int)$order->gem_qty,'delivery_method'=>$order->delivery_method]
                :['character_name'=>$order->character_name,'type'=>$order->type,'bars'=>(int)$order->gold_bar_qty,'pure'=>(int)$order->pure_gold_qty];
            foreach ([[],['claim'=>$claim]] as $checkpoint) {
                $order->forceFill(['delivery_data'=>$checkpoint,'server_id'=>$bot->server_id,'bot_id'=>$bot->id,'status'=>'processing'])->save();
                $before=$order->refresh()->getAttributes();Event::fake();
                $this->activity($module,$bot,['code'=>'name_mismatch','order_id'=>$order->id,'customer_message'=>'private'])->assertOk();
                Event::assertDispatched(UserEvent::class);
                Event::assertDispatched(TradingBotActivity::class,fn($event)=>!isset($event->activity['customer_message'],$event->activity['order_id']));
                $this->assertSame($before,$order->refresh()->getAttributes());
            }
        }
    }
}
