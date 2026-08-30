<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BalanceTransactionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_read_token_can_only_list_its_own_balance_history(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $admin = User::factory()->create();

        $this->transaction($user, $admin, Transaction::TYPE_ADMIN_CREDIT, 5000, 1000, 6000);
        $latest = $this->transaction($user, $admin, Transaction::TYPE_ADMIN_DEBIT, -2000, 6000, 4000);
        $this->transaction($otherUser, $admin, Transaction::TYPE_ADMIN_CREDIT, 999999, 0, 999999);

        Passport::actingAs($user, ['profile:read']);

        $this->getJson('/api/profile/balance-transactions?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.0.type', Transaction::TYPE_ADMIN_DEBIT)
            ->assertJsonPath('data.0.title', 'Admin trừ tiền')
            ->assertJsonPath('data.0.direction', 'debit')
            ->assertJsonPath('data.0.amount', -2000)
            ->assertJsonPath('data.0.absolute_amount', 2000)
            ->assertJsonPath('data.0.currency', 'VND')
            ->assertJsonPath('data.0.balance_before', 6000)
            ->assertJsonPath('data.0.balance_after', 4000)
            ->assertJsonPath('data.0.source', 'admin')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('data.0.idempotency_key')
            ->assertJsonMissingPath('data.0.performed_by');
    }

    public function test_balance_history_supports_type_direction_and_date_filters(): void
    {
        $user = User::factory()->create();
        $this->transaction($user, $user, Transaction::TYPE_ADMIN_DEBIT, -50000, 100000, 50000);
        $credit = $this->transaction($user, null, Transaction::TYPE_GOLD_ORDER_REFUND, 50000, 50000, 100000);

        Passport::actingAs($user, ['profile:read']);

        $this->getJson('/api/profile/balance-transactions?direction=credit&type=gold_order_refund&from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $credit->id)
            ->assertJsonPath('data.0.source', 'system');

        $this->getJson('/api/profile/balance-transactions?direction=debit')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', Transaction::TYPE_ADMIN_DEBIT)
            ->assertJsonPath('data.0.source', 'user');
    }

    public function test_balance_history_rejects_invalid_filters(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user, ['profile:read']);

        $this->getJson('/api/profile/balance-transactions?type=invalid&direction=sideways&from=20-08-2026&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'direction', 'from', 'per_page']);
    }

    public function test_balance_history_requires_authentication_and_profile_read_scope(): void
    {
        $this->getJson('/api/profile/balance-transactions')->assertUnauthorized();

        Passport::actingAs(User::factory()->create(), ['balance:deposit']);
        $this->getJson('/api/profile/balance-transactions')->assertForbidden();
    }

    private function transaction(
        User $user,
        ?User $performer,
        string $type,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
    ): Transaction {
        return Transaction::query()->create([
            'user_id' => $user->id,
            'performed_by' => $performer?->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => 'Giao dịch kiểm thử API.',
        ]);
    }
}
