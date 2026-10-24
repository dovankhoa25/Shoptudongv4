<?php

namespace Tests\Feature;

use App\Models\{Bot, GoldTransaction, InventoryMovement, Server, User};
use App\Services\GoldBarAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoldRefillCapacityTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private int $loginId;
    private string $secret = 'test-only-refill-capacity-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled', true);
        config()->set('trading_v3.hmac_secret', $this->secret);
        Event::fake();
        $this->server = Server::create(['name' => 'refill-test', 'status' => true]);
        $this->loginId = (int) DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert([
            'id' => $this->loginId, 'name' => 'test-login', 'ip' => 'test.invalid', 'port' => '14445',
        ]);
    }

    public static function assets(): array
    {
        return ['standard' => [457], 'nrj' => [2032]];
    }

    private function signed(string $path, array $data)
    {
        $body = json_encode($data);
        $time = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $tool = 'nro-trading-v3';

        return $this->putJson($path, $data, [
            'X-Tool-Id' => $tool, 'X-Tool-Timestamp' => $time, 'X-Tool-Nonce' => $nonce,
            'X-Tool-Signature' => hash_hmac('sha256', implode("\n", [
                'PUT', $path, $tool, $time, $nonce, hash('sha256', $body),
            ]), $this->secret),
        ]);
    }

    private function bot(string $role, int $asset, int $bars, int $otherBars = 999): Bot
    {
        return Bot::create([
            'name' => 'refill-test', 'account_name' => 'test-'.Str::uuid(), 'account_password' => 'test-password',
            'type' => $role, 'server_id' => $this->server->id, 'server_game_id' => $this->loginId,
            'gold_bar_qty' => $asset === 457 ? $bars : $otherBars,
            'nrj_gold_bar_qty' => $asset === 2032 ? $bars : $otherBars,
            'gold_qty' => 0, 'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '1',
            'coordinates' => '652-336', 'status' => true,
        ]);
    }

    private function waitingOrder(Bot $bot, int $asset, int $bars): GoldTransaction
    {
        $order = GoldTransaction::create([
            'type' => 'order', 'user_id' => User::factory()->create()->id, 'server_id' => $bot->server_id,
            'character_name' => 'testcustomer', 'gold_bar_template_id' => $asset, 'gold_bar_qty' => $bars,
            'pure_gold_qty' => 0, 'gold_qty' => $bars * GoldBarAsset::value($asset),
            'amount_vnd' => 10000, 'price_at_transaction' => 1000, 'status' => 'pending', 'updated_by' => 'web',
        ]);
        $this->signed("/app/v3/gold-transactions/{$order->id}", [
            'action' => 'start', 'bot_id' => $bot->id, 'gold_bar_template_id' => $asset, 'character_id' => 1234,
        ])->assertOk()->assertJsonPath('data.delivery_phase', 'waiting_stock');

        return $order;
    }

    private function transfer(Bot $from, Bot $to, int $asset, int $bars): array
    {
        return [
            'action' => 'claim', 'from_bot_id' => $from->id, 'to_bot_id' => $to->id,
            'gold_bar_template_id' => $asset, 'bars_qty' => $bars, 'pure_gold_qty' => 0,
        ];
    }

    private function finish(Bot $from, Bot $to, string $id, array $claim): void
    {
        $field = GoldBarAsset::field($claim['gold_bar_template_id']);
        $source = $from->only(['gold_qty', 'gold_bar_qty', 'nrj_gold_bar_qty']);
        $destination = $to->only(['gold_qty', 'gold_bar_qty', 'nrj_gold_bar_qty']);
        $source[$field] -= $claim['bars_qty'];
        $destination[$field] += $claim['bars_qty'];
        $payload = [
            ...$claim, 'action' => 'receipt', 'step_id' => (string) Str::uuid(),
            'from_snapshot' => [...$source, 'stock_version' => $from->stock_version + 1],
            'to_snapshot' => [...$destination, 'stock_version' => $to->stock_version + 1],
            'evidence' => ['kind' => 'transfer', 'confirmed' => true],
        ];
        $this->signed("/app/v3/gold-transfers/{$id}", $payload)->assertOk()->assertJsonPath('data.status', 'completed');
        $movements = InventoryMovement::count();
        $this->signed("/app/v3/gold-transfers/{$id}", $payload)->assertOk();
        $this->assertSame($movements, InventoryMovement::count(), 'Retrying a receipt must not move stock twice.');
    }

    #[DataProvider('assets')]
    public function test_refill_can_take_all_500_bars_even_when_waiting_order_needs_only_140(int $asset): void
    {
        $main = $this->bot('selling_main', $asset, 0);
        $aux = $this->bot('selling_sub,import_sub', $asset, 500);
        $order = $this->waitingOrder($main, $asset, 140);
        $id = (string) Str::uuid();
        $claim = $this->transfer($aux, $main, $asset, 500);
        $this->signed("/app/v3/gold-transfers/{$id}", $claim)->assertOk()->assertJsonPath('data.bars_qty', 500);
        $this->finish($aux, $main, $id, $claim);
        $field = GoldBarAsset::field($asset);
        $this->assertSame(0, $aux->refresh()->{$field});
        $this->assertSame(500, $main->refresh()->{$field});
        $this->assertSame(999, $main->{GoldBarAsset::field($asset === 457 ? 2032 : 457)});
        $this->assertSame(140, $order->refresh()->gold_bar_qty, 'Refill must not change the customer quote.');
        $this->assertSame('processing', $order->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    #[DataProvider('assets')]
    public function test_refill_is_limited_by_999_room_not_waiting_order_deficit(int $asset): void
    {
        $main = $this->bot('selling_main', $asset, 800);
        $aux = $this->bot('selling_sub', $asset, 500);
        $this->waitingOrder($main, $asset, 940);
        $oversized = $this->transfer($aux, $main, $asset, 200);
        $this->signed('/app/v3/gold-transfers/'.Str::uuid(), $oversized)->assertConflict();
        $this->assertSame(800, $main->refresh()->{GoldBarAsset::field($asset)});
        $id = (string) Str::uuid();
        $claim = $this->transfer($aux, $main, $asset, 199);
        $this->signed("/app/v3/gold-transfers/{$id}", $claim)->assertOk()->assertJsonPath('data.bars_qty', 199);
        $this->finish($aux, $main, $id, $claim);
        $this->assertSame(999, $main->refresh()->{GoldBarAsset::field($asset)});
        $this->assertSame(301, $aux->refresh()->{GoldBarAsset::field($asset)});
    }

    #[DataProvider('assets')]
    public function test_import_storage_requires_enabled_auxiliary_room_and_keeps_stock_when_full(int $asset): void
    {
        $main = $this->bot('import_main', $asset, 500);
        $full = $this->bot('import_sub', $asset, 999);
        $disabled = $this->bot('selling_sub', $asset, 0);
        $disabled->update(['status' => false]);
        $this->signed('/app/v3/gold-transfers/'.Str::uuid(), $this->transfer($main, $full, $asset, 1))->assertConflict();
        $this->signed('/app/v3/gold-transfers/'.Str::uuid(), $this->transfer($main, $disabled, $asset, 500))->assertConflict();
        $this->assertSame(500, $main->refresh()->{GoldBarAsset::field($asset)});
        $this->assertSame(0, InventoryMovement::where('movement_type', 'reservation')->count());
        $room = $this->bot('selling_sub', $asset, 800);
        $id = (string) Str::uuid();
        $claim = $this->transfer($main, $room, $asset, 199);
        $this->signed("/app/v3/gold-transfers/{$id}", $claim)->assertOk()->assertJsonPath('data.direction', 'storage');
        $this->finish($main, $room, $id, $claim);
        $this->assertSame(301, $main->refresh()->{GoldBarAsset::field($asset)});
        $this->assertSame(999, $room->refresh()->{GoldBarAsset::field($asset)});
    }
}
