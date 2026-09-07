<?php

namespace Tests\Feature;

use App\Events\UserEvent;
use App\Models\CarotRecharge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CarotRechargeRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.carot_app_key', 'carot-test-key');
        Event::fake([UserEvent::class]);
    }

    public function test_success_status_is_broadcast_to_the_customer_once(): void
    {
        $user = User::factory()->create(['balance' => 90000]);
        $recharge = $this->pendingRecharge($user);

        $endpoint = "/api/app/carot/recharges/{$recharge->id}/success";

        $this->withHeader('X-App-Key', 'carot-test-key')
            ->postJson($endpoint, ['message' => 'Da nap xong'])
            ->assertOk()
            ->assertJsonPath('data.status.value', CarotRecharge::STATUS_SUCCESS);

        Event::assertDispatched(UserEvent::class, fn (UserEvent $event): bool => $event->userId === $user->id
            && $event->type === 'order_status'
            && $event->payload['order_type'] === 'carot'
            && $event->payload['order_id'] === $recharge->id
            && $event->payload['status'] === CarotRecharge::STATUS_SUCCESS);
        Event::assertDispatchedTimes(UserEvent::class, 1);

        $this->withHeader('X-App-Key', 'carot-test-key')
            ->postJson($endpoint, ['message' => 'Desktop retry'])
            ->assertOk();

        Event::assertDispatchedTimes(UserEvent::class, 1);
    }

    public function test_failure_broadcasts_status_and_absolute_refunded_balance_once(): void
    {
        $user = User::factory()->create(['balance' => 90000]);
        $recharge = $this->pendingRecharge($user);
        $endpoint = "/api/app/carot/recharges/{$recharge->id}/failed";

        $this->withHeader('X-App-Key', 'carot-test-key')
            ->postJson($endpoint, ['message' => 'Nap that bai'])
            ->assertOk()
            ->assertJsonPath('data.status.value', CarotRecharge::STATUS_FAILED);

        $this->assertSame(100000, (int) $user->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'carot_recharge_refund',
            'amount' => 10000,
            'balance_after' => 100000,
        ]);
        Event::assertDispatched(UserEvent::class, fn (UserEvent $event): bool => $event->userId === $user->id
            && $event->type === 'order_status'
            && $event->payload['order_type'] === 'carot'
            && $event->payload['order_id'] === $recharge->id
            && $event->payload['status'] === CarotRecharge::STATUS_FAILED);
        Event::assertDispatched(UserEvent::class, fn (UserEvent $event): bool => $event->userId === $user->id
            && $event->type === 'update_balance'
            && $event->payload['amount'] === 10000
            && $event->payload['balance'] === 100000);
        Event::assertDispatchedTimes(UserEvent::class, 2);

        $this->withHeader('X-App-Key', 'carot-test-key')
            ->postJson($endpoint, ['message' => 'Desktop retry'])
            ->assertOk();

        $this->assertSame(100000, (int) $user->refresh()->balance);
        $this->assertSame(1, Transaction::withoutUserOwnedScope()
            ->where('type', 'carot_recharge_refund')
            ->where('related_id', $recharge->id)
            ->count());
        Event::assertDispatchedTimes(UserEvent::class, 2);
    }

    private function pendingRecharge(User $user): CarotRecharge
    {
        return CarotRecharge::query()->create([
            'user_id' => $user->id,
            'account_name' => 'tester@example.com',
            'server_id' => 1,
            'amount' => 10000,
            'carot' => 10,
            'transaction_code' => 'CAROT-TEST-'.uniqid(),
            'status' => CarotRecharge::STATUS_PENDING,
            'message' => 'Dang xu ly',
        ]);
    }
}
