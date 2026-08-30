<?php

namespace Tests\Unit;

use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Services\UserRealtimeNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_persists_complete_data_and_does_not_duplicate_an_idempotent_event(): void
    {
        $actor = User::factory()->create();
        $user = User::factory()->create(['balance' => 125000]);
        $metadata = [
            'source' => 'test',
            'order_id' => 456,
        ];

        $first = TransactionService::log(
            userId: $user->id,
            type: 'order_refund',
            amount: 25000,
            description: 'Hoàn tiền đơn #456',
            performedBy: $actor->id,
            related: User::class,
            relatedId: $user->id,
            oldBalance: 100000,
            newBalance: 125000,
            idempotencyKey: 'order-refund:456',
            metadata: $metadata,
        );

        $second = TransactionService::log(
            userId: $user->id,
            type: 'order_refund',
            amount: 25000,
            description: 'Hoàn tiền đơn #456',
            performedBy: $actor->id,
            related: User::class,
            relatedId: $user->id,
            oldBalance: 100000,
            newBalance: 125000,
            idempotencyKey: 'order-refund:456',
            metadata: $metadata,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertDatabaseHas('transactions', [
            'id' => $first->id,
            'user_id' => $user->id,
            'performed_by' => $actor->id,
            'amount' => 25000,
            'balance_before' => 100000,
            'balance_after' => 125000,
            'related_type' => User::class,
            'related_id' => (string) $user->id,
            'idempotency_key' => 'order-refund:456',
        ]);
        $this->assertSame($metadata, $first->fresh()->metadata);
    }

    public function test_log_rejects_reusing_an_idempotency_key_for_different_data(): void
    {
        $user = User::factory()->create(['balance' => 125000]);

        TransactionService::log(
            userId: $user->id,
            type: 'order_refund',
            amount: 25000,
            description: 'Hoàn tiền đơn #456',
            related: User::class,
            relatedId: $user->id,
            oldBalance: 100000,
            newBalance: 125000,
            idempotencyKey: 'order-refund:456',
            metadata: ['order_id' => 456],
        );

        try {
            TransactionService::log(
                userId: $user->id,
                type: 'order_refund',
                amount: 30000,
                description: 'Hoàn tiền đơn #456',
                related: User::class,
                relatedId: $user->id,
                oldBalance: 100000,
                newBalance: 130000,
                idempotencyKey: 'order-refund:456',
                metadata: ['order_id' => 456],
            );

            $this->fail('Expected a validation exception for conflicting idempotent data.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_adjust_balance_notifies_user_once_with_the_absolute_balance(): void
    {
        $actor = User::factory()->create();
        $user = User::factory()->create(['balance' => 100000]);

        $realtime = $this->mock(UserRealtimeNotifier::class);
        $realtime->shouldReceive('balanceChanged')
            ->once()
            ->with($user->id, 25000, 125000, \Mockery::type('string'));

        $service = app(TransactionService::class);

        $first = $service->adjustBalance(
            target: $user,
            actor: $actor,
            direction: 'credit',
            amount: 25000,
            description: 'Admin cộng tiền',
            idempotencyKey: 'admin-credit-test',
        );
        $second = $service->adjustBalance(
            target: $user,
            actor: $actor,
            direction: 'credit',
            amount: 25000,
            description: 'Admin cộng tiền',
            idempotencyKey: 'admin-credit-test',
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(125000, (int) $user->fresh()->balance);
    }
}
