<?php

namespace Tests\Feature;

use App\Events\UserEvent;
use App\Models\Card;
use App\Models\CardType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CardRechargeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.card_partner.url', 'https://partner.test/charging');
        config()->set('services.card_partner.id', 'partner-id');
        config()->set('services.card_partner.key', 'partner-secret');
        Event::fake([UserEvent::class]);
    }

    public function test_user_submits_card_and_successful_callback_credits_discounted_amount_once(): void
    {
        Http::fake([
            'https://partner.test/charging' => Http::response([
                'status' => 99,
                'message' => 'Pending',
                'trans_id' => 'PARTNER-001',
            ]),
        ]);

        $cardType = CardType::query()->create([
            'telco' => 'viettel',
            'discount_rate' => 20,
            'status' => true,
        ]);
        $user = User::factory()->create(['balance' => 10000]);
        Passport::actingAs($user, ['balance:deposit']);

        $response = $this->postJson('/api/recharge/card', [
            'telco' => 'VIETTEL',
            'amount' => '100000',
            'card_code' => '1234567890',
            'card_serial' => 'SERIAL123456',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Card::STATUS_PENDING)
            ->assertJsonPath('data.declared_value', 100000);

        $card = Card::query()->sole();
        $this->assertSame($cardType->id, $card->card_type_id);
        $this->assertFalse($card->loaded_type);
        $this->assertSame(99, $card->partner_status);
        $this->assertSame('Pending', $card->partner_message);
        $this->assertSame(200, $card->partner_http_status);
        $this->assertNotNull($card->partner_response_at);
        $this->assertNull($card->callback_received_at);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://partner.test/charging'
            && $request['request_id'] === $card->id
            && $request['partner_id'] === 'partner-id'
            && $request['sign'] === md5('partner-secret1234567890SERIAL123456'));

        $callback = [
            'request_id' => $card->id,
            'code' => '1234567890',
            'serial' => 'SERIAL123456',
            'callback_sign' => md5('partner-secret1234567890SERIAL123456'),
            'status' => 1,
            'value' => 100000,
            'amount' => 80000,
            'trans_id' => 'PARTNER-001',
        ];

        $this->postJson('/api/charge/callback', $callback)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credited_amount', 80000)
            ->assertJsonPath('status', Card::STATUS_COMPLETED);

        $this->assertSame(90000, (int) $user->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_CARD_DEPOSIT,
            'amount' => 80000,
            'balance_before' => 10000,
            'balance_after' => 90000,
            'related_type' => Card::class,
            'related_id' => (string) $card->id,
            'idempotency_key' => 'card:'.$card->id,
        ]);

        $this->postJson('/api/charge/callback', $callback)
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('duplicate', true);

        $this->assertSame(90000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(1, $card->refresh()->partner_status);
        $this->assertNotNull($card->callback_received_at);
    }

    public function test_unknown_partner_status_is_failed_and_diagnostics_are_preserved(): void
    {
        Http::fake([
            'https://partner.test/charging' => Http::response([
                'status' => 102,
                'message' => 'INPUT_DATA_INCORRECT',
            ]),
        ]);

        CardType::query()->create([
            'telco' => 'viettel',
            'discount_rate' => 20,
            'status' => true,
        ]);
        Passport::actingAs(User::factory()->create(), ['balance:deposit']);

        $this->postJson('/api/recharge/card', [
            'telco' => 'viettel',
            'amount' => 100000,
            'card_code' => '9999999999',
            'card_serial' => 'SERIAL999999',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Đối tác từ chối xử lý thẻ')
            ->assertJsonPath('data.status', Card::STATUS_FAILED)
            ->assertJsonPath('data.note', 'Đối tác từ chối xử lý thẻ')
            ->assertJsonMissingPath('data.partner_status')
            ->assertJsonMissingPath('data.partner_message')
            ->assertJsonMissingPath('data.partner_http_status')
            ->assertJsonMissingPath('data.partner_response_at')
            ->assertJsonMissingPath('data.callback_received_at');

        $card = Card::query()->sole();

        $this->assertSame(Card::STATUS_FAILED, $card->status);
        $this->assertSame(102, $card->partner_status);
        $this->assertSame('INPUT_DATA_INCORRECT', $card->partner_message);
        $this->assertSame(200, $card->partner_http_status);
        $this->assertNotNull($card->partner_response_at);
        $this->assertNull($card->callback_received_at);
    }

    public function test_card_endpoints_validate_scope_active_telco_duplicate_and_signature(): void
    {
        Http::fake([
            'https://partner.test/charging' => Http::response(['status' => 99]),
        ]);

        CardType::query()->create([
            'telco' => 'viettel',
            'discount_rate' => 10,
            'status' => true,
        ]);
        CardType::query()->create([
            'telco' => 'disabled',
            'discount_rate' => 0,
            'status' => false,
        ]);

        $this->getJson('/api/card-types')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.telco', 'viettel');

        $payload = [
            'telco' => 'viettel',
            'amount' => 100000,
            'card_code' => '1234567890',
            'card_serial' => 'SERIAL123456',
        ];

        $this->postJson('/api/charge', $payload)->assertUnauthorized();

        Passport::actingAs(User::factory()->create(), ['profile:write']);
        $this->postJson('/api/charge', $payload)->assertForbidden();

        $user = User::factory()->create();
        Passport::actingAs($user, ['balance:deposit']);
        $this->postJson('/api/charge', $payload)->assertCreated();
        $this->postJson('/api/charge', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_code');

        $card = Card::query()->sole();
        $this->postJson('/api/charge/callback', [
            'request_id' => $card->id,
            'code' => $card->code,
            'serial' => $card->serial,
            'callback_sign' => str_repeat('0', 32),
            'status' => 1,
            'value' => 100000,
        ])->assertUnauthorized()->assertJsonPath('success', false);

        $this->assertSame(0, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }
}
