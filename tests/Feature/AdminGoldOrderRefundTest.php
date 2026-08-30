<?php

namespace Tests\Feature;

use App\Models\GoldTransaction;
use App\Models\Server;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminGoldOrderRefundTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('refundableStatuses')]
    public function test_admin_can_cancel_and_refund_a_gold_order_only_once(string $status): void
    {
        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $customer = User::factory()->create(['balance' => 50_000]);
        $server = Server::query()->create([
            'name' => 'server-1',
            'name_view' => 'Server 1',
            'status' => true,
        ]);
        $order = GoldTransaction::query()->create([
            'type' => GoldTransaction::TYPE_ORDER,
            'user_id' => $customer->id,
            'server_id' => $server->id,
            'character_name' => 'refund-hero',
            'amount_vnd' => 10_000,
            'gold_qty' => 1_000,
            'gold_bar_qty' => 0,
            'pure_gold_qty' => 1_000,
            'price_at_transaction' => 10,
            'status' => $status,
            'updated_by' => 'web',
        ]);

        $this->actingAs($admin)
            ->from('/admin/orders')
            ->put("/admin/orders/{$order->id}/status", [
                'status' => GoldTransaction::STATUS_CANCELLED,
                'cancel_reason' => 'Khách yêu cầu huỷ đơn',
                'refund_amount' => 7_000,
            ])
            ->assertRedirect('/admin/orders')
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(GoldTransaction::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->refunded_at);
        $this->assertSame(57_000, (int) $customer->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'type' => Transaction::TYPE_GOLD_ORDER_REFUND,
            'idempotency_key' => "gold-order-refund:{$order->id}",
            'performed_by' => $admin->id,
            'amount' => 7_000,
        ]);

        $this->actingAs($admin)
            ->from('/admin/orders')
            ->put("/admin/orders/{$order->id}/status", [
                'status' => GoldTransaction::STATUS_CANCELLED,
                'cancel_reason' => 'Gửi lại yêu cầu',
                'refund_amount' => 7_000,
            ])
            ->assertRedirect('/admin/orders')
            ->assertSessionHasErrors('status');

        $this->assertSame(57_000, (int) $customer->refresh()->balance);
        $this->assertSame(1, Transaction::query()
            ->where('idempotency_key', "gold-order-refund:{$order->id}")
            ->count());
    }

    public static function refundableStatuses(): array
    {
        return [
            'pending' => [GoldTransaction::STATUS_PENDING],
            'processing' => [GoldTransaction::STATUS_PROCESSING],
        ];
    }
}
