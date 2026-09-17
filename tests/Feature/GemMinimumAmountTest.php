<?php

namespace Tests\Feature;

use App\Models\GemPrice;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GemMinimumAmountTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('amounts')]
    public function test_active_price_controls_minimum_before_debit(int $minimum): void
    {
        Event::fake([\App\Events\UserEvent::class]);
        $server = Server::create(['name' => 's1', 'name_view' => 'Server 1', 'status' => true]);
        $user = User::factory()->create(['balance' => 100000]);
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 13, 'min_amount' => $minimum, 'status' => true]);
        GemPrice::create(['server_id' => $server->id, 'multiplier' => 13, 'min_amount' => 99999, 'status' => false]);
        $payload = ['server_id' => $server->id, 'character_name' => 'hero', 'money_amount' => $minimum - 1];
        \Laravel\Passport\Passport::actingAs($user, ['profile:read', 'profile:write']);
        $this->postJson('/api/gem/orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('money_amount');
        $this->assertSame(100000, (int) $user->fresh()->balance);
        $this->assertDatabaseCount('gem_transactions', 0);
        $payload['money_amount'] = $minimum;
        $this->postJson('/api/gem/orders', $payload)->assertCreated();
        $this->assertSame(100000 - $minimum, (int) $user->fresh()->balance);
    }

    public static function amounts(): array
    {
        return ['lower' => [3000], 'default' => [10000], 'higher' => [20000]];
    }

    public function test_default_minimum(): void
    {
        $server = Server::create(['name' => 's1', 'name_view' => 'Server 1', 'status' => true]);
        $price = GemPrice::create(['server_id' => $server->id, 'multiplier' => 13, 'status' => true]);
        $this->assertSame(10000, $price->fresh()->min_amount);
    }

    public function test_admin_update_refreshes_public_minimum_and_new_price_inherits_it(): void
    {
        $server = Server::create(['name' => 's1', 'name_view' => 'Server 1', 'status' => true]);
        $price = GemPrice::create(['server_id' => $server->id, 'multiplier' => 13, 'status' => true]);
        $this->getJson('/api/gem/servers')->assertOk()->assertJsonPath('data.0.gem_prices.0.min_amount', 10000);
        \Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->put('/admin/gem-prices/'.$price->id, [
            'server_id' => $server->id, 'multiplier' => 13, 'status' => true, 'min_amount' => 20000,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->getJson('/api/gem/servers')->assertOk()->assertJsonPath('data.0.gem_prices.0.min_amount', 20000);
        $this->post('/admin/gem-prices', [
            'server_id' => $server->id, 'multiplier' => 14, 'status' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(20000, GemPrice::getCurrentMultiplier($server->id)->min_amount);
    }
}
