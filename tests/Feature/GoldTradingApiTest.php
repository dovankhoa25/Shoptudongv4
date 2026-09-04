<?php

namespace Tests\Feature;

use App\Models\GemPrice;
use App\Models\GemTransaction;
use App\Models\GoldPrice;
use App\Models\GoldTransaction;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class GoldTradingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_gold_and_gem_history_require_authentication(): void
    {
        $this->getJson('/api/gold/orders')->assertUnauthorized();
        $this->getJson('/api/gem/orders')->assertUnauthorized();
    }

    public function test_gold_history_only_returns_the_authenticated_users_transactions(): void
    {
        [$server] = $this->market();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $matching = $this->goldTransaction($user, $server, [
            'type' => GoldTransaction::TYPE_ORDER,
            'character_name' => 'hero one',
            'status' => GoldTransaction::STATUS_PROCESSING,
        ]);
        $this->goldTransaction($user, $server, [
            'type' => GoldTransaction::TYPE_IMPORT,
            'character_name' => 'hero one',
        ]);
        $this->goldTransaction($otherUser, $server, [
            'type' => GoldTransaction::TYPE_ORDER,
            'character_name' => 'hero one',
            'status' => GoldTransaction::STATUS_PROCESSING,
        ]);

        Passport::actingAs($user);

        $this->getJson('/api/gold/orders?type=order&status=processing&search=hero&per_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.server_id', $server->id)
            ->assertJsonPath('data.0.server_name', 'Server 1')
            ->assertJsonPath('data.0.total_gold', 37000000)
            ->assertJsonPath('data.0.estimated_gold', 37000000)
            ->assertJsonPath('data.0.gold_bar_qty', 1)
            ->assertJsonPath('data.0.pure_gold_qty', 0)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_buying_gold_debits_balance_and_creates_a_linked_balance_transaction(): void
    {
        [$server] = $this->market();
        $user = User::factory()->create(['balance' => 1000000]);
        Passport::actingAs($user);

        $this->postJson('/api/orders', [
            'server_id' => $server->id,
            'character_name' => '  Hero One  ',
            'money_amount' => 500000,
            'gold_type' => 'bar',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requested_amount', 500000)
            ->assertJsonPath('data.charged_amount', 435295)
            ->assertJsonPath('data.unused_amount', 64705)
            ->assertJsonPath('data.gold_total', 37000000)
            ->assertJsonPath('data.gold_bars', 1)
            ->assertJsonPath('data.pure_gold', 0);

        $order = GoldTransaction::query()->sole();

        $this->assertSame(564705, (int) $user->refresh()->balance);
        $this->assertSame('heroone', $order->character_name);
        $this->assertSame(435295, (int) $order->amount_vnd);
        $this->assertSame(37000000, (int) $order->gold_qty);
        $this->assertSame(1, (int) $order->gold_bar_qty);
        $this->assertSame(0, (int) $order->pure_gold_qty);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'mua vàng',
            'amount' => -435295,
            'balance_before' => 1000000,
            'balance_after' => 564705,
            'related_type' => GoldTransaction::class,
            'related_id' => (string) $order->id,
        ]);
    }

    public function test_gold_purchase_below_one_bar_is_rejected_without_debiting_balance(): void
    {
        [$server] = $this->market();
        $user = User::factory()->create(['balance' => 100000]);
        Passport::actingAs($user);

        $this->postJson('/api/orders', [
            'server_id' => $server->id,
            'character_name' => 'Hero One',
            'money_amount' => 5000,
            'gold_type' => 'bar',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.gold_bar_qty', 0)
            ->assertJsonPath('data.minimum_amount', 435295);

        $this->assertSame(100000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('gold_transactions', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_gold_purchase_with_insufficient_balance_does_not_create_an_order(): void
    {
        [$server] = $this->market();
        $user = User::factory()->create(['balance' => 400000]);
        Passport::actingAs($user);

        $this->postJson('/api/orders', [
            'server_id' => $server->id,
            'character_name' => 'Hero One',
            'money_amount' => 500000,
            'gold_type' => 'bar',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('money_amount');

        $this->assertSame(400000, (int) $user->refresh()->balance);
        $this->assertDatabaseCount('gold_transactions', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_importing_gold_creates_an_order_without_debiting_balance(): void
    {
        [$server] = $this->market();
        $user = User::factory()->create(['balance' => 25000]);
        Passport::actingAs($user);

        $this->postJson('/api/imports', [
            'server_id' => $server->id,
            'character_name' => 'Seller One',
            'gold_bar_qty' => 1,
            'pure_gold_qty' => 50000000,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.server_id', $server->id)
            ->assertJsonPath('data.server_name', 'Server 1')
            ->assertJsonPath('data.total_gold', 87000000);

        $this->assertSame(25000, (int) $user->refresh()->balance);
        $this->assertDatabaseHas('gold_transactions', [
            'user_id' => $user->id,
            'type' => GoldTransaction::TYPE_IMPORT,
            'gold_qty' => 87000000,
            'gold_bar_qty' => 1,
            'pure_gold_qty' => 50000000,
            'amount_vnd' => 870000,
        ]);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_import_requires_at_least_one_gold_quantity(): void
    {
        [$server] = $this->market();
        Passport::actingAs(User::factory()->create());

        $this->postJson('/api/imports', [
            'server_id' => $server->id,
            'character_name' => 'Seller One',
            'gold_bar_qty' => 0,
            'pure_gold_qty' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['gold_bar_qty', 'pure_gold_qty']);

        $this->postJson('/api/imports', [
            'server_id' => $server->id,
            'character_name' => 'Seller One',
            'gold_bar_qty' => 0,
            'pure_gold_qty' => 49999999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('pure_gold_qty');

        $this->assertDatabaseCount('gold_transactions', 0);
    }

    public function test_buying_gems_debits_balance_and_gem_history_is_user_scoped(): void
    {
        [$server] = $this->market();
        GemPrice::query()->create([
            'server_id' => $server->id,
            'multiplier' => 1.5,
            'status' => true,
        ]);
        $user = User::factory()->create(['balance' => 100000]);
        $otherUser = User::factory()->create();
        GemTransaction::query()->create([
            'user_id' => $otherUser->id,
            'server_id' => $server->id,
            'character_name' => 'other',
            'amount_vnd' => 10000,
            'gem_qty' => 15,
            'price_at_transaction' => 1.5,
            'status' => GemTransaction::STATUS_PENDING,
            'updated_by' => 'web',
        ]);
        Passport::actingAs($user);

        $this->postJson('/api/gem/orders', [
            'server_id' => $server->id,
            'character_name' => 'Gem Hero',
            'money_amount' => 3000,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.server_id', $server->id)
            ->assertJsonPath('data.gem_qty', 5);

        $order = GemTransaction::query()->where('user_id', $user->id)->sole();
        $this->assertSame(97000, (int) $user->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'mua ngọc',
            'amount' => -3000,
            'balance_before' => 100000,
            'balance_after' => 97000,
            'related_type' => GemTransaction::class,
            'related_id' => (string) $order->id,
        ]);

        $this->getJson('/api/gem/orders?limit=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.server_id', $server->id)
            ->assertJsonPath('data.0.server_name', 'Server 1');
    }

    /** @return array{Server, GoldPrice} */
    private function market(): array
    {
        $server = Server::query()->create([
            'name' => 'server-1',
            'name_view' => 'Server 1',
            'status' => true,
        ]);
        $price = GoldPrice::query()->create([
            'server_id' => $server->id,
            'price' => 85,
            'import_price' => 100,
            'status' => true,
        ]);

        return [$server, $price];
    }

    private function goldTransaction(User $user, Server $server, array $overrides = []): GoldTransaction
    {
        return GoldTransaction::query()->create([
            'type' => GoldTransaction::TYPE_ORDER,
            'user_id' => $user->id,
            'server_id' => $server->id,
            'character_name' => 'hero',
            'amount_vnd' => 435295,
            'gold_qty' => 37000000,
            'gold_bar_qty' => 1,
            'pure_gold_qty' => 0,
            'price_at_transaction' => 85,
            'status' => GoldTransaction::STATUS_PENDING,
            'updated_by' => 'web',
            ...$overrides,
        ]);
    }
}
