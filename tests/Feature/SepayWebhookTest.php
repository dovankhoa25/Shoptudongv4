<?php

namespace Tests\Feature;

use App\Events\UserEvent;
use App\Models\AtmTopup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SepayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sepay.webhook_key', 'sepay-test-key');
        config()->set('services.sepay.transfer_prefix', 'shop');
        Event::fake([UserEvent::class]);
    }

    public function test_incoming_sepay_webhook_credits_user_and_records_both_histories(): void
    {
        $user = User::factory()->create(['balance' => 50000]);
        $payload = $this->payload($user, 75172732, 100000);

        $this->postJson('/api/webhook/sepay', $payload, $this->authorization())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('amount', 100000)
            ->assertJsonPath('balance', 150000);

        $user->refresh();
        $this->assertSame(150000, (int) $user->balance);

        $topup = AtmTopup::query()->sole();
        $this->assertSame('75172732', $topup->provider_transaction_id);
        $this->assertSame($user->id, $topup->user_id);
        $this->assertSame('shop'.$user->id, $topup->payment_code);
        $this->assertSame(100000, (int) $topup->amount);
        $this->assertEquals($payload, $topup->payload);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'performed_by' => null,
            'type' => Transaction::TYPE_BANK_DEPOSIT,
            'amount' => 100000,
            'balance_before' => 50000,
            'balance_after' => 150000,
            'related_type' => AtmTopup::class,
            'related_id' => (string) $topup->id,
            'idempotency_key' => 'sepay:75172732',
        ]);
    }

    public function test_duplicate_sepay_id_returns_false_and_never_credits_twice(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $payload = $this->payload($user, 75172732, 100000);

        $this->postJson('/api/webhook/sepay', $payload, $this->authorization())
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/webhook/sepay', $payload, $this->authorization())
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('transaction_id', '75172732');

        $this->assertSame(100000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('atm_topups', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_payment_prefix_can_be_changed_from_configuration(): void
    {
        config()->set('services.sepay.transfer_prefix', 'nro');
        $user = User::factory()->create(['balance' => 0]);
        $payload = $this->payload($user, 99, 20000);
        $payload['code'] = 'NRO'.$user->id;

        $this->postJson('/api/webhook/sepay', $payload, $this->authorization())
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(20000, (int) $user->refresh()->balance);
    }

    public function test_webhook_rejects_bad_credentials_outgoing_money_and_invalid_code(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $payload = $this->payload($user, 1, 100000);

        $this->postJson('/api/webhook/sepay', $payload, ['Authorization' => 'Apikey wrong'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $outgoing = [...$payload, 'transferType' => 'out'];
        $this->postJson('/api/webhook/sepay', $outgoing, $this->authorization())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transferType');

        $invalidCode = [...$payload, 'code' => 'other'.$user->id];
        $this->postJson('/api/webhook/sepay', $invalidCode, $this->authorization())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame(0, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('atm_topups', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    /** @return array<string, mixed> */
    private function payload(User $user, int $id, int $amount): array
    {
        return [
            'gateway' => 'MBBank',
            'transactionDate' => '2026-08-20 21:58:27',
            'accountNumber' => '0357602845',
            'subAccount' => 'VQRQAKUOX9442',
            'code' => 'shop'.$user->id,
            'content' => 'Chuyen khoan shop'.$user->id,
            'transferType' => 'in',
            'description' => 'BankAPINotify shop'.$user->id,
            'transferAmount' => $amount,
            'referenceCode' => 'FT26233120302804',
            'accumulated' => 0,
            'id' => $id,
        ];
    }

    /** @return array<string, string> */
    private function authorization(): array
    {
        return ['Authorization' => 'Apikey sepay-test-key'];
    }
}
