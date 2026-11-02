<?php

namespace Tests\Feature;

use App\Models\CardType;
use App\Models\User;
use App\Services\Deposit\CardRechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OriginIpErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.5']);
    }

    public function test_card_submission_does_not_return_infrastructure_exception_details(): void
    {
        CardType::create(['telco' => 'viettel', 'discount_rate' => 20, 'status' => true]);
        Passport::actingAs(User::factory()->create(), ['balance:deposit']);
        $this->mock(CardRechargeService::class)->shouldReceive('submit')->once()
            ->andThrow(new \RuntimeException('Connection failed at 203.0.113.42:3306 /srv/private'));

        $response = $this->postJson('/api/recharge/card', [
            'telco' => 'VIETTEL', 'amount' => 100000,
            'card_code' => 'TESTCARD123', 'card_serial' => 'TESTSERIAL123',
        ])->assertStatus(503)->assertJsonPath('success', false);

        $this->assertStringNotContainsString('203.0.113.42', $response->getContent());
        $this->assertStringNotContainsString('/srv/private', $response->getContent());
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_callback_does_not_return_infrastructure_exception_details(): void
    {
        $this->mock(CardRechargeService::class)->shouldReceive('processCallback')->once()
            ->andThrow(new \RuntimeException('Connection failed at 203.0.113.42:3306 /srv/private'));

        $response = $this->postJson('/api/charge/callback', [
            'code' => 'TESTCARD123', 'serial' => 'TESTSERIAL123',
            'callback_sign' => str_repeat('a', 32), 'status' => 1,
        ])->assertStatus(503)->assertJsonPath('message', 'Tạm thời chưa thể xử lý callback.');

        $this->assertStringNotContainsString('203.0.113.42', $response->getContent());
        $this->assertStringNotContainsString('/srv/private', $response->getContent());
    }
}
