<?php

namespace Tests\Feature;

use App\Events\UserEvent;
use App\Models\AtmTopup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GpmPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bank_deposit.provider' => 'gpmpay',
            'services.gpmpay.auth_mode' => 'hmac',
            'services.gpmpay.webhook_secret' => 'gpmpay-test-secret',
        ]);
        Event::fake([UserEvent::class]);
    }

    public function test_real_signed_transfer_credits_user_and_preserves_original_payload(): void
    {
        $user = User::factory()->create(['balance' => 50000]);
        $payload = $this->payload('Chuyen tien SHOP'.$user->id);
        $payload['counterAccount'] = '9876543210';
        $payload['counterName'] = 'NGUYEN VAN A';
        $payload['counterBankBin'] = '970436';
        $payload['extraField'] = ['retained' => true];

        $this->send($payload)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credited', true)
            ->assertJsonPath('needs_review', false)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('balance', 150000);

        $topup = AtmTopup::query()->sole();
        $this->assertSame('gpmpay', $topup->provider);
        $this->assertSame('shop'.$user->id, $topup->payment_code);
        $this->assertSame(AtmTopup::STATUS_COMPLETED, $topup->status);
        $this->assertNull($topup->review_reason);
        $this->assertSame($payload, $topup->payload);
        $this->assertSame('2026-10-11T03:35:50+00:00', $topup->transaction_at->utc()->format('c'));
        $this->assertSame(150000, (int) $user->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_BANK_DEPOSIT,
            'amount' => 100000,
            'balance_before' => 50000,
            'balance_after' => 150000,
            'related_type' => AtmTopup::class,
            'related_id' => (string) $topup->id,
            'idempotency_key' => 'gpmpay:'.$payload['id'],
        ]);
        Event::assertDispatched(UserEvent::class, fn (UserEvent $event) => $event->userId === $user->id && $event->type === 'update_balance');
    }

    public function test_shop_and_vang_codes_are_case_insensitive_and_allow_bank_wrappers(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $contents = [
            'shop'.$user->id,
            'vang'.$user->id,
            'MB:VCB.CT tu NGUYEN VAN A (VaNg'.$user->id.') FT123',
            'NAP SHOP'.$user->id.';shop'.$user->id,
            'Nội dung: shop'.$user->id,
        ];

        foreach ($contents as $content) {
            $this->send($this->payload($content))->assertOk()->assertJsonPath('credited', true);
        }

        $this->assertSame(count($contents) * 100000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('transactions', count($contents));
    }

    public function test_missing_malformed_ambiguous_and_overflow_codes_are_saved_for_review(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $contents = [
            '',
            'Chuyen tien',
            'shop '.$user->id,
            'vang '.$user->id,
            'shop-'.$user->id,
            'xshop'.$user->id,
            'shop'.$user->id.'abc',
            'shop'.$user->id.'_extra',
            'shop'.$user->id.'0', // A different, nonexistent ID, never a substring match.
            'shop0',
            'vang000'.$user->id,
            'shop9223372036854775808',
            'shop'.str_repeat('9', 200),
            'shop'.$user->id.' vang'.$user->id,
        ];

        foreach ($contents as $content) {
            $payload = $this->payload($content);
            $this->send($payload)->assertOk()
                ->assertJsonPath('credited', false)
                ->assertJsonPath('needs_review', true)
                ->assertJsonPath('user_id', null);

            $topup = AtmTopup::query()->where('provider_transaction_id', $payload['id'])->sole();
            $this->assertSame(AtmTopup::STATUS_NEEDS_REVIEW, $topup->status);
            $this->assertNotEmpty($topup->review_reason);
            $this->assertSame($payload, $topup->payload);
        }

        $this->assertSame(0, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('atm_topups', count($contents));
        $this->assertDatabaseCount('transactions', 0);
        Event::assertNotDispatched(UserEvent::class);
    }

    public function test_description_alias_is_used_only_when_content_is_missing(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $payload = $this->payload('');
        unset($payload['content']);
        $payload['description'] = 'nap vang'.$user->id;
        $this->send($payload)->assertOk()->assertJsonPath('credited', true);

        $invalid = $this->payload('noi dung sai');
        $invalid['description'] = 'shop'.$user->id;
        $this->send($invalid)->assertOk()->assertJsonPath('needs_review', true);
        $this->assertSame(100000, (int) $user->refresh()->balance);
    }

    public function test_unknown_and_deleted_users_and_balance_limit_remain_uncredited(): void
    {
        $deleted = User::factory()->create(['balance' => 0]);
        $deleted->delete();
        $full = User::factory()->create(['balance' => TransactionService::MAX_BALANCE]);

        foreach (['shop999999999999', 'shop'.$deleted->id, 'vang'.$full->id] as $content) {
            $this->send($this->payload($content))->assertOk()
                ->assertJsonPath('credited', false)->assertJsonPath('needs_review', true);
        }

        $this->assertSame(TransactionService::MAX_BALANCE, (int) $full->refresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_completed_retry_and_changed_retry_payload_never_credit_again(): void
    {
        $first = User::factory()->create(['balance' => 0]);
        $second = User::factory()->create(['balance' => 0]);
        $payload = $this->payload('shop'.$first->id);
        $this->send($payload)->assertOk()->assertJsonPath('credited', true);
        $this->send($payload)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('credited', false);

        $changed = [...$payload, 'content' => 'vang'.$second->id, 'transferAmount' => 999999];
        $this->send($changed)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('credited', false);

        $this->assertSame(100000, (int) $first->refresh()->balance);
        $this->assertSame(0, (int) $second->refresh()->balance);
        $this->assertSame($payload, AtmTopup::query()->sole()->payload);
        $this->assertDatabaseCount('atm_topups', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_unmatched_retry_is_acknowledged_without_overwriting_or_auto_resolving(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $payload = $this->payload('khong co ma');
        $this->send($payload)->assertOk()->assertJsonPath('needs_review', true);
        $this->send($payload)->assertOk()->assertJsonPath('duplicate', true);
        $changed = [...$payload, 'content' => 'shop'.$user->id];
        $this->send($changed)->assertOk()->assertJsonPath('needs_review', true)->assertJsonPath('duplicate', true);

        $this->assertSame(0, (int) $user->refresh()->balance);
        $this->assertSame($payload, AtmTopup::query()->sole()->payload);
        $this->assertDatabaseCount('atm_topups', 1);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_documented_verification_simulation_and_outgoing_transfers_are_ignored(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $verification = [...$this->payload('GPMPay webhook verification'),
            'id' => '00000000-0000-0000-0000-000000000000',
            'gateway' => 'GPMPAY', 'accountNumber' => '0000000000',
            'transferAmount' => 1, 'referenceCode' => 'GPMPAY-VERIFY',
            'source' => 'SIMULATED', 'test' => true,
        ];
        $this->send($verification)->assertOk()->assertJsonPath('reason', 'verification');
        $this->send([...$this->payload('shop'.$user->id), 'source' => 'SIMULATED'])
            ->assertOk()->assertJsonPath('reason', 'simulated');
        $this->send([...$this->payload('shop'.$user->id), 'transferType' => 'out'])
            ->assertOk()->assertJsonPath('reason', 'outgoing');

        $this->assertSame(0, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('atm_topups', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_inactive_provider_ignores_real_payments_but_still_accepts_verification(): void
    {
        config(['services.bank_deposit.provider' => 'sepay']);
        $user = User::factory()->create(['balance' => 0]);
        $this->send($this->payload('shop'.$user->id))->assertOk()->assertJsonPath('reason', 'provider_disabled');
        $this->send([...$this->payload('shop'.$user->id), 'test' => true])->assertOk()->assertJsonPath('reason', 'verification');
        $this->assertDatabaseCount('atm_topups', 0);
        $this->assertSame(0, (int) $user->refresh()->balance);
    }

    public function test_hmac_rejects_missing_malformed_stale_future_and_modified_body_signatures(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $payload = $this->payload('shop'.$user->id);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = [
            [],
            ['X-GPMPay-Signature' => 'broken'],
            ['X-GPMPay-Signature' => 't='.now()->getTimestamp().',v1=aa'],
            $this->signature($body, now()->getTimestamp() - 301),
            $this->signature($body, now()->getTimestamp() + 301),
            $this->signature($body.' '),
        ];

        foreach ($headers as $header) {
            $this->send($payload, $header)->assertUnauthorized();
        }

        // Signed raw bytes may contain formatting and spaces; never re-encode to verify.
        $pretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->send($payload, $this->signature($pretty), $pretty)->assertOk()->assertJsonPath('credited', true);
        $this->assertSame(100000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_api_key_is_exact_raw_secret_and_no_authentication_mode_is_disabled(): void
    {
        config(['services.gpmpay.auth_mode' => 'api_key']);
        $user = User::factory()->create(['balance' => 0]);
        $payload = $this->payload('vang'.$user->id);

        foreach (['wrong', 'Bearer gpmpay-test-secret', 'Apikey gpmpay-test-secret'] as $header) {
            $this->send($payload, ['Authorization' => $header])->assertUnauthorized();
        }
        $this->send($payload, ['Authorization' => 'gpmpay-test-secret'])->assertOk()->assertJsonPath('credited', true);

        config(['services.gpmpay.auth_mode' => 'none']);
        $this->send($this->payload('shop'.$user->id), [])->assertUnauthorized();
        config(['services.gpmpay.auth_mode' => 'hmac', 'services.gpmpay.webhook_secret' => '']);
        $this->send($this->payload('shop'.$user->id))->assertUnauthorized();
        $this->assertSame(100000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_invalid_amount_id_date_and_missing_source_are_rejected(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $base = $this->payload('shop'.$user->id);
        foreach (['transferAmount' => 1.5, 'id' => '1', 'transactionDate' => '2026-10-11', 'source' => null] as $key => $value) {
            $this->send([...$base, $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('atm_topups', 0);
        $this->assertSame(0, (int) $user->refresh()->balance);
    }

    public function test_ledger_failure_rolls_back_topup_and_balance_and_retry_can_succeed(): void
    {
        $user = User::factory()->create(['balance' => 50000]);
        $payload = $this->payload('shop'.$user->id);
        $listener = 'eloquent.creating: '.Transaction::class;
        $failLedger = true;
        Event::listen($listener, static function () use (&$failLedger): void {
            if ($failLedger) {
                throw new \RuntimeException('Ledger unavailable');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->send($payload);
            $this->fail('The ledger failure must escape so GPMPay retries.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Ledger unavailable', $exception->getMessage());
        } finally {
            $failLedger = false;
        }

        $this->assertSame(50000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('atm_topups', 0);
        $this->assertDatabaseCount('transactions', 0);
        Event::assertNotDispatched(UserEvent::class);
        $this->send($payload)->assertOk()->assertJsonPath('credited', true);
        $this->assertSame(150000, (int) $user->refresh()->balance);
    }

    /** @return array<string, mixed> */
    private function payload(string $content): array
    {
        return [
            'id' => (string) Str::uuid(),
            'gateway' => 'VCB',
            'transactionDate' => '2026-10-11T03:35:50.306Z',
            'accountNumber' => '0123456789',
            'subAccount' => null,
            'content' => $content,
            'description' => null,
            'transferType' => 'in',
            'transferAmount' => 100000,
            'referenceCode' => 'FT26'.Str::random(12),
            'accumulated' => null,
            'source' => 'REAL',
        ];
    }

    /** @return array<string, string> */
    private function signature(string $body, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();

        return ['X-GPMPay-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'gpmpay-test-secret')];
    }

    private function send(array $payload, ?array $headers = null, ?string $body = null): TestResponse
    {
        $body ??= json_encode($payload, JSON_THROW_ON_ERROR);
        $headers ??= $this->signature($body);

        return $this->call('POST', '/api/webhook/gpmpay', [], [], [],
            $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'Accept' => 'application/json', ...$headers]), $body);
    }
}
