<?php

namespace Tests\Feature;

use App\Events\UserEvent;
use App\Models\GemTransaction;
use App\Models\Server;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GemOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.app_api_key', 'desktop-test-key');
        config()->set('trading.gem_order_pending_timeout_minutes', 10);
        config()->set('trading.gem_order_refund_grace_minutes', 5);
        Event::fake([UserEvent::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_job_only_closes_pending_gem_orders_then_refunds_after_grace(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $server = $this->server();
        $user = User::factory()->create(['balance' => 50000]);
        $pending = $this->gemOrder($user, $server, [
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ]);
        $processing = $this->gemOrder($user, $server, [
            'character_name' => 'processing-hero',
            'status' => GemTransaction::STATUS_PROCESSING,
            'updated_by' => 'app',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('gold:cancel-stale')->assertSuccessful();

        $pending->refresh();
        $this->assertSame(GemTransaction::STATUS_CANCELLED, $pending->status);
        $this->assertNotNull($pending->cancel_requested_at);
        $this->assertNull($pending->refunded_at);
        $this->assertSame(GemTransaction::STATUS_PROCESSING, $processing->refresh()->status);
        $this->assertSame(50000, (int) $user->refresh()->balance);

        Carbon::setTestNow(now()->addMinutes(4)->addSeconds(59));
        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(50000, (int) $user->refresh()->balance);

        Carbon::setTestNow(now()->addSecond());
        $this->artisan('gold:cancel-stale')->assertSuccessful();

        $pending->refresh();
        $this->assertSame(GemTransaction::STATUS_REFUNDED, $pending->status);
        $this->assertNotNull($pending->refunded_at);
        $this->assertSame(60000, (int) $user->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'type' => Transaction::TYPE_GEM_ORDER_REFUND,
            'idempotency_key' => "gem-order-timeout-refund:{$pending->id}",
            'amount' => 10000,
        ]);

        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(60000, (int) $user->refresh()->balance);
        $this->assertSame(1, Transaction::query()
            ->where('idempotency_key', "gem-order-timeout-refund:{$pending->id}")
            ->count());
    }

    public function test_app_can_recover_a_timeout_cancelled_gem_order_during_grace(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $server = $this->server();
        $user = User::factory()->create(['balance' => 50000]);
        $order = $this->gemOrder($user, $server, [
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ]);

        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(GemTransaction::STATUS_CANCELLED, $order->refresh()->status);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->putJson(
            "/app/v1/gem-transactions/{$order->id}",
            ['status' => GemTransaction::STATUS_PROCESSING],
            ['X-APP-KEY' => 'desktop-test-key'],
        )->assertOk()
            ->assertJsonPath('data.status', GemTransaction::STATUS_PROCESSING)
            ->assertJsonMissingPath('data.cancel_requested_at')
            ->assertJsonMissingPath('data.refunded_at');

        $order->refresh();
        $this->assertSame(GemTransaction::STATUS_PROCESSING, $order->status);
        $this->assertNull($order->cancel_requested_at);

        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('gold:cancel-stale')->assertSuccessful();
        $this->assertSame(GemTransaction::STATUS_PROCESSING, $order->refresh()->status);
        $this->assertSame(50000, (int) $user->refresh()->balance);
        $this->assertDatabaseMissing('transactions', [
            'idempotency_key' => "gem-order-timeout-refund:{$order->id}",
        ]);
    }

    #[DataProvider('refundableStatuses')]
    public function test_admin_can_refund_pending_or_processing_gem_order_only_once(string $status): void
    {
        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $server = $this->server();
        $customer = User::factory()->create(['balance' => 50000]);
        $order = $this->gemOrder($customer, $server, [
            'status' => $status,
            'updated_by' => 'app',
        ]);

        $this->actingAs($admin)
            ->get("/admin/gem-orders/{$order->id}")
            ->assertOk();

        $this->actingAs($admin)
            ->from("/admin/gem-orders/{$order->id}")
            ->post("/admin/gem-orders/{$order->id}/refund", [
                'refund_reason' => 'Admin đã kiểm tra và xác nhận hoàn',
                'refund_amount' => 10000,
            ])
            ->assertRedirect("/admin/gem-orders/{$order->id}")
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(GemTransaction::STATUS_REFUNDED, $order->status);
        $this->assertNotNull($order->refunded_at);
        $this->assertSame(60000, (int) $customer->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'type' => Transaction::TYPE_GEM_ORDER_REFUND,
            'idempotency_key' => "gem-order-refund:{$order->id}",
            'performed_by' => $admin->id,
            'amount' => 10000,
        ]);

        $this->actingAs($admin)
            ->from("/admin/gem-orders/{$order->id}")
            ->post("/admin/gem-orders/{$order->id}/refund", [
                'refund_reason' => 'Thử hoàn lần hai',
                'refund_amount' => 10000,
            ])
            ->assertRedirect("/admin/gem-orders/{$order->id}")
            ->assertSessionHasErrors('status');

        $this->assertSame(60000, (int) $customer->refresh()->balance);
        $this->assertSame(1, Transaction::query()
            ->where('idempotency_key', "gem-order-refund:{$order->id}")
            ->count());
    }

    public function test_admin_cannot_refund_a_completed_gem_order(): void
    {
        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $server = $this->server();
        $customer = User::factory()->create(['balance' => 50000]);
        $order = $this->gemOrder($customer, $server, [
            'status' => GemTransaction::STATUS_COMPLETED,
            'updated_by' => 'app',
        ]);

        $this->actingAs($admin)
            ->from("/admin/gem-orders/{$order->id}")
            ->post("/admin/gem-orders/{$order->id}/refund", [
                'refund_reason' => 'Thử hoàn đơn đã hoàn thành',
                'refund_amount' => 10000,
            ])
            ->assertRedirect("/admin/gem-orders/{$order->id}")
            ->assertSessionHasErrors('status');

        $this->assertSame(GemTransaction::STATUS_COMPLETED, $order->refresh()->status);
        $this->assertNull($order->refunded_at);
        $this->assertSame(50000, (int) $customer->refresh()->balance);
        $this->assertDatabaseMissing('transactions', [
            'idempotency_key' => "gem-order-refund:{$order->id}",
        ]);
    }

    public static function refundableStatuses(): array
    {
        return [
            'pending' => [GemTransaction::STATUS_PENDING],
            'processing' => [GemTransaction::STATUS_PROCESSING],
        ];
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'server-1',
            'name_view' => 'Server 1',
            'status' => true,
        ]);
    }

    private function gemOrder(User $user, Server $server, array $overrides = []): GemTransaction
    {
        $order = new GemTransaction;
        $order->forceFill([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'character_name' => 'hero',
            'item' => null,
            'amount_vnd' => 10000,
            'gem_qty' => 1000,
            'price_at_transaction' => 10,
            'status' => GemTransaction::STATUS_PENDING,
            'updated_by' => 'web',
            ...$overrides,
        ])->save();

        return $order;
    }
}
