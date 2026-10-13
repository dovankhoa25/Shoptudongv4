<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\AtmTopup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminBankTopupResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (AppPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('admin', 'web');
    }

    public function test_resolution_uses_the_bank_amount_records_the_actor_and_moves_the_order_to_completed(): void
    {
        $admin = $this->admin();
        $recipient = User::factory()->create(['balance' => 50000]);
        $topup = $this->pending();
        $original = $topup->payload;
        $reason = $topup->review_reason;

        $this->actingAs($admin)->postJson($this->endpoint($topup), [
            'user_id' => (string) $recipient->id, 'amount' => 999, 'performed_by' => $recipient->id,
        ])->assertOk()->assertJsonPath('balance', 150000)->assertJsonPath('user_id', $recipient->id);

        $this->assertSame(150000, (int) $recipient->refresh()->balance);
        $topup->refresh();
        $this->assertSame(AtmTopup::STATUS_COMPLETED, $topup->status);
        $this->assertSame($recipient->id, $topup->user_id);
        $this->assertNull($topup->review_reason);
        $this->assertNull($topup->payment_code);
        $this->assertSame($original, $topup->payload);
        $this->assertSame('', $topup->content);
        $transaction = Transaction::withoutUserOwnedScope()->sole();
        $this->assertSame($admin->id, $transaction->performed_by);
        $this->assertSame(Transaction::TYPE_BANK_DEPOSIT, $transaction->type);
        $this->assertSame(100000, $transaction->amount);
        $this->assertSame(50000, $transaction->balance_before);
        $this->assertSame(150000, $transaction->balance_after);
        $this->assertSame(AtmTopup::class, $transaction->related_type);
        $this->assertSame((string) $topup->id, (string) $transaction->related_id);
        $this->assertSame('gpmpay:'.$topup->provider_transaction_id, $transaction->idempotency_key);
        $this->assertSame('Xử lý nạp tiền ID #'.$topup->id.' - giao dịch '.$topup->provider_transaction_id.'.', $transaction->description);
        $this->assertSame($reason, $transaction->metadata['original_review_reason']);

        $this->get(route('admin.deposits.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('reviewTopups.data', 0)
            ->where('stats.pending_bank_topups', 0)
            ->where('bankTopups.data.0.id', $topup->id)
            ->where('bankTopups.data.0.processed_by.id', $admin->id)
            ->where('stats.bank_total', 100000));
        $this->get(route('admin.transactions.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data.0.performed_by.id', $admin->id)
            ->where('transactions.data.0.description', $transaction->description));
    }

    public function test_repeated_or_competing_resolution_does_not_credit_a_second_user(): void
    {
        $first = User::factory()->create(['balance' => 0]);
        $second = User::factory()->create(['balance' => 0]);
        $topup = $this->pending();
        $this->actingAs($this->admin())->postJson($this->endpoint($topup), ['user_id' => $first->id])->assertOk();
        $this->postJson($this->endpoint($topup), ['user_id' => $first->id])->assertConflict();
        $this->actingAs($this->admin())->postJson($this->endpoint($topup), ['user_id' => $second->id])->assertConflict();
        $this->assertSame(100000, (int) $first->refresh()->balance);
        $this->assertSame(0, (int) $second->refresh()->balance);
        $this->assertSame($first->id, $topup->refresh()->user_id);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_resolution_requires_both_view_and_credit_permissions_and_recipient_policy(): void
    {
        $topup = $this->pending();
        $recipient = User::factory()->create(['balance' => 0]);
        foreach ([AppPermission::DepositsView->value, AppPermission::UsersAdjustBalance->value] as $permission) {
            $actor = User::factory()->create();
            $actor->assignRole('admin');
            $actor->givePermissionTo($permission);
            $this->actingAs($actor)->postJson($this->endpoint($topup), ['user_id' => $recipient->id])->assertForbidden();
        }
        $actor = $this->admin();
        $peer = $this->admin();
        foreach ([$actor, $peer] as $target) {
            $this->actingAs($actor)->postJson($this->endpoint($topup), ['user_id' => $target->id])->assertForbidden();
        }
        $this->assertSame(0, (int) $recipient->refresh()->balance);
        $this->assertSame(AtmTopup::STATUS_NEEDS_REVIEW, $topup->refresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_unknown_deleted_and_malformed_user_ids_are_rejected(): void
    {
        $topup = $this->pending();
        $deleted = User::factory()->create();
        $deleted->delete();
        $this->actingAs($this->admin());
        foreach ([null, 0, 'abc', 1.5, '9223372036854775808', 999999999, $deleted->id] as $id) {
            $this->postJson($this->endpoint($topup), ['user_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        }
        $this->assertSame(AtmTopup::STATUS_NEEDS_REVIEW, $topup->refresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_completed_orders_and_existing_credit_history_cannot_be_credited(): void
    {
        $recipient = User::factory()->create(['balance' => 50000]);
        $completed = $this->pending();
        $completed->forceFill(['status' => AtmTopup::STATUS_COMPLETED, 'user_id' => $recipient->id])->save();
        $this->actingAs($this->admin())->postJson($this->endpoint($completed), ['user_id' => $recipient->id])->assertConflict();

        $pending = $this->pending();
        Transaction::withoutUserOwnedScope()->create([
            'user_id' => $recipient->id, 'type' => Transaction::TYPE_BANK_DEPOSIT, 'amount' => 100000,
            'balance_before' => 0, 'balance_after' => 100000,
            'idempotency_key' => 'gpmpay:'.$pending->provider_transaction_id,
        ]);
        $this->postJson($this->endpoint($pending), ['user_id' => $recipient->id])->assertConflict();
        $this->assertSame(50000, (int) $recipient->refresh()->balance);
        $this->assertSame(AtmTopup::STATUS_NEEDS_REVIEW, $pending->refresh()->status);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_balance_overflow_keeps_the_order_unresolved_without_credit(): void
    {
        $recipient = User::factory()->create(['balance' => TransactionService::MAX_BALANCE]);
        $topup = $this->pending();
        $this->actingAs($this->admin())->postJson($this->endpoint($topup), ['user_id' => $recipient->id])
            ->assertUnprocessable();
        $this->assertSame(TransactionService::MAX_BALANCE, (int) $recipient->refresh()->balance);
        $this->assertSame(AtmTopup::STATUS_NEEDS_REVIEW, $topup->refresh()->status);
        $this->assertNull($topup->user_id);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_ledger_failure_rolls_back_the_balance_and_order_and_can_be_retried(): void
    {
        $recipient = User::factory()->create(['balance' => 50000]);
        $topup = $this->pending();
        $failLedger = true;
        Event::listen('eloquent.creating: '.Transaction::class, static function () use (&$failLedger): void {
            if ($failLedger) {
                throw new \RuntimeException('Ledger unavailable');
            }
        });
        $this->actingAs($this->admin())->withoutExceptionHandling();
        try {
            $this->postJson($this->endpoint($topup), ['user_id' => $recipient->id]);
            $this->fail('Ledger failure must roll back the resolution.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Ledger unavailable', $exception->getMessage());
        } finally {
            $failLedger = false;
        }
        $this->assertSame(50000, (int) $recipient->refresh()->balance);
        $this->assertSame(AtmTopup::STATUS_NEEDS_REVIEW, $topup->refresh()->status);
        $this->assertNull($topup->user_id);
        $this->assertDatabaseCount('transactions', 0);
        $this->postJson($this->endpoint($topup), ['user_id' => $recipient->id])->assertOk();
        $this->assertSame(150000, (int) $recipient->refresh()->balance);
    }

    public function test_gpmpay_retry_after_manual_resolution_is_acknowledged_without_another_credit(): void
    {
        config(['services.bank_deposit.provider' => 'both', 'services.gpmpay.auth_mode' => 'hmac',
            'services.gpmpay.webhook_secret' => 'resolution-test-secret']);
        $recipient = User::factory()->create(['balance' => 0]);
        $topup = $this->pending();
        $this->actingAs($this->admin())->postJson($this->endpoint($topup), ['user_id' => $recipient->id])->assertOk();
        $body = json_encode($topup->payload, JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();
        $this->call('POST', '/api/webhook/gpmpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GPMPAY_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'resolution-test-secret'),
        ], $body)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('credited', false);
        $this->assertSame(100000, (int) $recipient->refresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo([AppPermission::DepositsView->value, AppPermission::UsersAdjustBalance->value,
            AppPermission::TransactionsView->value]);

        return $admin;
    }

    private function endpoint(AtmTopup $topup): string
    {
        return route('admin.deposits.bank-topups.resolve', $topup);
    }

    private function pending(): AtmTopup
    {
        $payload = [
            'id' => (string) Str::uuid(), 'gateway' => 'MBBank', 'transactionDate' => now()->toISOString(),
            'accountNumber' => '0123456789', 'content' => '', 'transferType' => 'in',
            'transferAmount' => 100000, 'source' => 'REAL',
        ];

        return AtmTopup::query()->create([
            'user_id' => null, 'provider' => 'dvkpay', 'provider_transaction_id' => $payload['id'],
            'gateway' => 'MBBank', 'transaction_at' => now(), 'account_number' => $payload['accountNumber'],
            'payment_code' => null, 'content' => '', 'transfer_type' => 'in', 'amount' => 100000,
            'status' => AtmTopup::STATUS_NEEDS_REVIEW,
            'review_reason' => 'Nội dung không có mã shop<ID> hoặc vang<ID> hợp lệ.', 'payload' => $payload,
        ]);
    }
}
