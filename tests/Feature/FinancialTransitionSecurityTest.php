<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\WithdrawalRequestController;
use App\Models\GemPrice;
use App\Models\GemTransaction;
use App\Models\GoldPrice;
use App\Models\GoldTransaction;
use App\Models\Server;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialTransitionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function markets(): array
    {
        return ['gold' => ['gold'], 'gem' => ['gem']];
    }

    #[DataProvider('markets')]
    public function test_purchase_rechecks_pending_order_created_after_initial_read(string $market): void
    {
        Event::fake([\App\Events\UserEvent::class, \App\Events\AdminEvent::class]);
        $server = Server::create(['name' => 's1', 'name_view' => 'Server 1', 'status' => true]);
        $user = User::factory()->create(['balance' => 1000000]);
        GoldPrice::create(['server_id' => $server->id, 'price' => 85, 'import_price' => 100, 'status' => true]);
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 1.5, 'status' => true]);
        Passport::actingAs($user, ['profile:read', 'profile:write']);
        $inserted = false;
        // Deterministically simulate another purchase between the early read and user-row lock.
        // This verifies stale-read handling; actual MySQL concurrent locking needs integration testing.
        DB::listen(function ($query) use ($market, $user, $server, &$inserted): void {
            if ($inserted || ! str_contains($query->sql, 'select exists')
                || ! str_contains($query->sql, $market.'_transactions')) {
                return;
            }
            $inserted = true;
            $data = ['user_id' => $user->id, 'server_id' => $server->id,
                'character_name' => 'heroone', 'amount_vnd' => 10000,
                'status' => 'pending', 'price_at_transaction' => 1.5, 'updated_by' => 'web'];
            if ($market === 'gem') {
                GemTransaction::create([...$data, 'gem_qty' => 15]);
            } else {
                GoldTransaction::create([...$data, 'type' => 'order', 'gold_qty' => 37000000,
                    'gold_bar_qty' => 1, 'pure_gold_qty' => 0]);
            }
        });
        $this->postJson($market === 'gem' ? '/api/gem/orders' : '/api/orders', [
            'server_id' => $server->id, 'character_name' => 'Hero One',
            'money_amount' => $market === 'gem' ? 10000 : 500000,
        ])->assertUnprocessable();
        $this->assertTrue($inserted);
        $this->assertSame(1000000, (int) $user->fresh()->balance);
        $this->assertDatabaseCount($market.'_transactions', 1);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_stale_pending_withdrawal_cannot_overwrite_rejection(): void
    {
        $user = User::factory()->create(['balance' => 12345]);
        $withdrawal = $this->withdrawal($user);
        WithdrawalRequest::whereKey($withdrawal->id)->update(['status' => 'rejected']);
        Route::middleware('web')->post('/test/stale-approve', fn (Request $request) => app(WithdrawalRequestController::class)->approve($request, $withdrawal));
        $this->actingAs($user)->post('/test/stale-approve', ['fee' => 0, 'fee_type' => 'amount'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertSame(12345, (int) $user->fresh()->balance);
    }

    public function test_stale_paid_action_cannot_overwrite_existing_payment(): void
    {
        $user = User::factory()->create();
        $withdrawal = $this->withdrawal($user);
        $withdrawal->update(['status' => 'approved']);
        WithdrawalRequest::whereKey($withdrawal->id)->update(['status' => 'paid', 'note' => 'Original payment']);
        Route::middleware('web')->post('/test/stale-paid', fn (Request $request) => app(WithdrawalRequestController::class)->markPaid($request, $withdrawal));
        $this->actingAs($user)->post('/test/stale-paid', ['note' => 'Overwrite attempt'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame('Original payment', $withdrawal->fresh()->note);
    }

    public function test_pending_withdrawal_can_be_approved_and_paid_normally(): void
    {
        $user = User::factory()->create();
        $withdrawal = $this->withdrawal($user);
        Route::middleware('web')->post('/test/approve', fn (Request $request) => app(WithdrawalRequestController::class)->approve($request, $withdrawal));
        Route::middleware('web')->post('/test/paid', fn (Request $request) => app(WithdrawalRequestController::class)->markPaid($request, $withdrawal->fresh()));
        $this->actingAs($user)->post('/test/approve', ['fee' => 5, 'fee_type' => 'percentage'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(9500, (int) $withdrawal->fresh()->net_amount);
        $this->post('/test/paid', ['note' => 'Verified payment'])->assertSessionHas('success');
        $this->assertSame('paid', $withdrawal->fresh()->status);
    }

    private function withdrawal(User $user): WithdrawalRequest
    {
        return WithdrawalRequest::create(['user_id' => $user->id, 'amount' => 10000,
            'status' => 'pending', 'bank_name' => 'Test bank', 'bank_account_number' => '123',
            'bank_account_name' => 'Test user']);
    }
}
