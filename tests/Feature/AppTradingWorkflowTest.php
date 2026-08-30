<?php

namespace Tests\Feature;

use App\Events\UserEvent;
use App\Models\Bot;
use App\Models\GemBot;
use App\Models\GoldTransaction;
use App\Models\Server;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppTradingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.app_api_key', 'desktop-test-key');
        config()->set('trading.gold_order_pending_timeout_minutes', 10);
        config()->set('trading.gold_order_refund_grace_minutes', 5);
        Event::fake([UserEvent::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_app_routes_fail_closed_and_public_bot_inventory_is_hidden(): void
    {
        $server = $this->server();
        $bot = $this->bot($server, gold: 456000000, bars: 12);
        $gemBot = GemBot::query()->create([
            'name' => 'Gem Public',
            'account_name' => 'gem-login',
            'account_password' => 'gem-password',
            'server_id' => $server->id,
            'server_game_id' => 1,
            'gem_qty' => 987654,
            'map_name' => 'Đảo Kame',
            'map_id' => '1',
            'area_number' => '2',
            'proxy' => '127.0.0.1:8080',
            'status' => true,
        ]);

        config()->set('services.app_api_key', null);
        $this->getJson('/app/v2/bots')->assertUnauthorized();

        config()->set('services.app_api_key', 'desktop-test-key');
        $this->getJson('/app/v2/bots')->assertUnauthorized();
        $this->getJson('/app/v2/bots', $this->appHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $bot->id)
            ->assertJsonPath('data.0.account_name', 'bot-login')
            ->assertJsonPath('data.0.account_password', 'bot-password')
            ->assertJsonPath('data.0.gold_qty', 456000000)
            ->assertJsonPath('data.0.gold_bar_qty', 12)
            ->assertJsonPath('data.0.proxy', '127.0.0.1:9000');

        $this->getJson("/api/bots?server_id={$server->id}&type=selling_main")
            ->assertOk()
            ->assertJsonPath('data.0.id', $bot->id)
            ->assertJsonPath('data.0.gold_qty', 0)
            ->assertJsonPath('data.0.gold_bar_qty', 0)
            ->assertJsonMissingPath('data.0.account_name')
            ->assertJsonMissingPath('data.0.account_password')
            ->assertJsonMissingPath('data.0.proxy');

        $this->getJson("/api/gem/bots?server_id={$server->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $gemBot->id)
            ->assertJsonMissingPath('data.0.gem_qty')
            ->assertJsonMissingPath('data.0.account_name')
            ->assertJsonMissingPath('data.0.account_password')
            ->assertJsonMissingPath('data.0.proxy');
    }

    public function test_gold_app_can_move_from_processing_to_completed_after_updated_by_becomes_app(): void
    {
        $server = $this->server();
        $user = User::factory()->create();
        $bot = $this->bot($server, gold: 900000000, bars: 20);
        $order = $this->goldOrder($user, $server);
        $payload = [
            'bot_id' => $bot->id,
            'gold_qty' => 800000000,
            'gold_bar_qty' => 18,
        ];

        $this->putJson(
            "/app/v2/gold-transactions/{$order->id}",
            [...$payload, 'status' => GoldTransaction::STATUS_PROCESSING],
            $this->appHeaders(),
        )->assertOk()->assertJsonPath('bot_id', $bot->id);

        $this->assertDatabaseHas('gold_transactions', [
            'id' => $order->id,
            'status' => GoldTransaction::STATUS_PROCESSING,
            'updated_by' => 'app',
            'bot_id' => null,
        ]);

        $this->putJson(
            "/app/v2/gold-transactions/{$order->id}",
            [...$payload, 'status' => GoldTransaction::STATUS_COMPLETED],
            $this->appHeaders(),
        )->assertOk()
            ->assertJsonPath('bot_id', $bot->id)
            ->assertJsonPath('gold_qty', 800000000)
            ->assertJsonPath('gold_bar_qty', 18);

        $this->assertDatabaseHas('gold_transactions', [
            'id' => $order->id,
            'status' => GoldTransaction::STATUS_COMPLETED,
            'updated_by' => 'app',
            'bot_id' => null,
        ]);
        $this->assertSame(800000000, (int) $bot->refresh()->gold_qty);
        $this->assertSame(18, (int) $bot->gold_bar_qty);
        $this->assertDatabaseCount('inventory_movements', 1);
        Event::assertDispatched(UserEvent::class, fn (UserEvent $event): bool => $event->userId === $user->id
            && $event->type === 'order_status'
            && $event->payload['order_id'] === $order->id
            && $event->payload['status'] === GoldTransaction::STATUS_COMPLETED);

        // Desktop retry sau timeout mạng vẫn nhận response cũ và không trừ kho lần hai.
        $this->putJson(
            "/app/v2/gold-transactions/{$order->id}",
            [...$payload, 'status' => GoldTransaction::STATUS_COMPLETED],
            $this->appHeaders(),
        )->assertOk()->assertJsonPath('bot_id', $bot->id);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_stale_pending_order_is_closed_then_refunded_after_grace_but_processing_is_untouched(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $server = $this->server();
        $user = User::factory()->create(['balance' => 50000]);
        $pending = $this->goldOrder($user, $server, [
            'amount_vnd' => 10000,
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ]);
        $processing = $this->goldOrder($user, $server, [
            'character_name' => 'processinghero',
            'status' => GoldTransaction::STATUS_PROCESSING,
            'updated_by' => 'app',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('gold:cancel-stale')->assertSuccessful();

        $pending->refresh();
        $this->assertSame(GoldTransaction::STATUS_CANCELLED, $pending->status);
        $this->assertNotNull($pending->cancel_requested_at);
        $this->assertNull($pending->refunded_at);
        $this->assertSame(50000, (int) $user->refresh()->balance);
        $this->assertSame(GoldTransaction::STATUS_PROCESSING, $processing->refresh()->status);

        Carbon::setTestNow(now()->addMinutes(4)->addSeconds(59));
        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(50000, (int) $user->refresh()->balance);

        Carbon::setTestNow(now()->addSecond());
        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(60000, (int) $user->refresh()->balance);
        $this->assertNotNull($pending->refresh()->refunded_at);
        $this->assertDatabaseHas('transactions', [
            'idempotency_key' => "gold-order-timeout-refund:{$pending->id}",
            'amount' => 10000,
        ]);

        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(60000, (int) $user->refresh()->balance);
        $this->assertSame(1, Transaction::query()
            ->where('idempotency_key', "gold-order-timeout-refund:{$pending->id}")
            ->count());
    }

    public function test_desktop_can_complete_a_just_cancelled_order_during_grace_without_refund(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $server = $this->server();
        $user = User::factory()->create(['balance' => 50000]);
        $bot = $this->bot($server, gold: 900000000, bars: 20);
        $order = $this->goldOrder($user, $server, [
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ]);

        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(GoldTransaction::STATUS_CANCELLED, $order->refresh()->status);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->putJson(
            "/app/v2/gold-transactions/{$order->id}",
            [
                'status' => GoldTransaction::STATUS_COMPLETED,
                'bot_id' => $bot->id,
                'gold_qty' => 800000000,
                'gold_bar_qty' => 18,
            ],
            $this->appHeaders(),
        )->assertOk();

        $order->refresh();
        $this->assertSame(GoldTransaction::STATUS_COMPLETED, $order->status);
        $this->assertNull($order->cancel_requested_at);

        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(50000, (int) $user->refresh()->balance);
        $this->assertDatabaseMissing('transactions', [
            'idempotency_key' => "gold-order-timeout-refund:{$order->id}",
        ]);
    }

    /** @return array<string, string> */
    private function appHeaders(): array
    {
        return ['X-APP-KEY' => 'desktop-test-key'];
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'server-1',
            'name_view' => 'Server 1',
            'status' => true,
        ]);
    }

    private function bot(Server $server, int $gold, int $bars): Bot
    {
        return Bot::query()->create([
            'name' => 'Bot Public',
            'account_name' => 'bot-login',
            'account_password' => 'bot-password',
            'type' => 'selling_main',
            'server_id' => $server->id,
            'server_game_id' => 1,
            'gold_qty' => $gold,
            'gold_bar_qty' => $bars,
            'map_name' => 'Đảo Kame',
            'map_id' => '1',
            'area_number' => '2',
            'proxy' => '127.0.0.1:9000',
            'status' => true,
        ]);
    }

    private function goldOrder(User $user, Server $server, array $overrides = []): GoldTransaction
    {
        return GoldTransaction::query()->create([
            'type' => GoldTransaction::TYPE_ORDER,
            'user_id' => $user->id,
            'server_id' => $server->id,
            'character_name' => 'hero',
            'amount_vnd' => 10000,
            'gold_qty' => 850000,
            'gold_bar_qty' => 0,
            'pure_gold_qty' => 850000,
            'price_at_transaction' => 85,
            'status' => GoldTransaction::STATUS_PENDING,
            'updated_by' => 'web',
            ...$overrides,
        ]);
    }
}
