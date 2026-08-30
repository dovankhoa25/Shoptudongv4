<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminBalanceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (AppPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('ctv', 'web');
    }

    public function test_authorized_admin_can_credit_a_lower_level_user_and_history_is_recorded(): void
    {
        [$actor, $target] = $this->actorAndTarget(10000);
        $key = (string) Str::uuid();

        $response = $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), [
            'direction' => 'credit',
            'amount' => 25000,
            'description' => 'Bù giao dịch nạp tiền #123.',
            'idempotency_key' => $key,
        ]);

        $response->assertOk()
            ->assertJsonPath('balance', 35000);
        $this->assertSame(35000, (int) $target->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $target->id,
            'performed_by' => $actor->id,
            'type' => Transaction::TYPE_ADMIN_CREDIT,
            'amount' => 25000,
            'balance_before' => 10000,
            'balance_after' => 35000,
            'idempotency_key' => $key,
        ]);
    }

    public function test_authorized_admin_can_debit_a_lower_level_user(): void
    {
        [$actor, $target] = $this->actorAndTarget(10000);

        $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), [
            'direction' => 'debit',
            'amount' => 4000,
            'description' => 'Thu hồi khoản cộng nhầm.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('balance', 6000);

        $this->assertSame(6000, (int) $target->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $target->id,
            'type' => Transaction::TYPE_ADMIN_DEBIT,
            'amount' => -4000,
            'balance_before' => 10000,
            'balance_after' => 6000,
        ]);
    }

    public function test_debit_cannot_make_the_balance_negative(): void
    {
        [$actor, $target] = $this->actorAndTarget(1000);

        $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), [
            'direction' => 'debit',
            'amount' => 1001,
            'description' => 'Khoản trừ không hợp lệ.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(1000, (int) $target->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_repeated_idempotency_key_does_not_apply_the_adjustment_twice(): void
    {
        [$actor, $target] = $this->actorAndTarget(10000);
        $payload = [
            'direction' => 'credit',
            'amount' => 5000,
            'description' => 'Thử gửi lại cùng một giao dịch.',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), $payload)->assertOk();
        $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), $payload)
            ->assertOk()
            ->assertJsonPath('balance', 15000);

        $this->assertSame(15000, (int) $target->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_admin_cannot_adjust_a_peer_balance_even_with_permission(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['balance' => 10000]);
        $actor->assignRole('admin');
        $target->assignRole('admin');
        $actor->givePermissionTo(AppPermission::UsersAdjustBalance->value);

        $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), [
            'direction' => 'credit',
            'amount' => 5000,
            'description' => 'Không được phép chỉnh admin ngang cấp.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertSame(10000, (int) $target->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_route_rejects_admin_without_balance_permission(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $actor->assignRole('admin');
        $target->assignRole('ctv');

        $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), [
            'direction' => 'credit',
            'amount' => 5000,
            'description' => 'Không có quyền.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    public function test_initial_balance_on_user_creation_is_also_recorded(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $actor->givePermissionTo([
            AppPermission::UsersCreate->value,
            AppPermission::UsersAdjustBalance->value,
        ]);

        $response = $this->actingAs($actor)->postJson(route('admin.users.store'), [
            'username' => 'new-wallet-user',
            'email' => 'new-wallet@example.com',
            'password' => 'secret123',
            'balance' => 75000,
        ]);

        $response->assertOk()->assertJsonPath('user.balance', '75000');
        $createdUser = User::query()->where('username', 'new-wallet-user')->firstOrFail();
        $this->assertDatabaseHas('transactions', [
            'user_id' => $createdUser->id,
            'performed_by' => $actor->id,
            'type' => Transaction::TYPE_ADMIN_CREDIT,
            'amount' => 75000,
            'balance_before' => 0,
            'balance_after' => 75000,
        ]);
    }

    public function test_user_index_exposes_the_balance_action_for_an_allowed_target(): void
    {
        [$actor, $target] = $this->actorAndTarget(10000);

        $this->actingAs($actor)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->where('users.data.0.id', $target->id)
                ->where('users.data.0.can.adjust_balance', true));
    }

    public function test_authorized_admin_can_view_the_recorded_transaction_history(): void
    {
        [$actor, $target] = $this->actorAndTarget(10000);

        $this->actingAs($actor)->postJson(route('admin.users.balance.adjust', $target), [
            'direction' => 'credit',
            'amount' => 5000,
            'description' => 'Giao dịch dùng để kiểm tra trang lịch sử.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Transactions/Index')
                ->where('transactions.data.0.user.id', $target->id)
                ->where('transactions.data.0.performed_by.id', $actor->id)
                ->where('transactions.data.0.amount', 5000)
                ->where('transactions.data.0.balance_after', 15000));
    }

    /** @return array{User, User} */
    private function actorAndTarget(int $balance): array
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['balance' => $balance]);
        $actor->assignRole('admin');
        $target->assignRole('ctv');
        $actor->givePermissionTo([
            AppPermission::UsersAdjustBalance->value,
            AppPermission::UsersView->value,
            AppPermission::TransactionsView->value,
        ]);

        return [$actor, $target];
    }
}
