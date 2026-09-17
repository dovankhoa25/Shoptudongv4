<?php
namespace Tests\Feature;

use App\Helpers\AccountEncrypt;
use App\Models\{User, Nick, NickOrder, Category, GameType};
use Illuminate\Support\Facades\{DB, Cache};
use Laravel\Passport\Passport;
use Tests\TestCase;

class NickPurchaseConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real commits are required for the after-commit failure case. Isolate in memory.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
    }

    private function fixture(): array
    {
        $buyer = User::factory()->create(['balance' => 500000]);
        $seller = User::factory()->create(['balance' => 100000]);
        $game = GameType::create(['name' => 'Test NRO']);
        $category = Category::create(['game_type_id' => $game->id, 'name' => 'Test nick', 'template' => 'default', 'is_public' => true, 'status' => 'active']);
        $nick = Nick::create(['account_name' => 'fixture-account', 'account_password' => AccountEncrypt::encrypt('fixture-password'),
            'price' => 200000, 'listing_type' => 'normal', 'category_id' => $category->id, 'user_id' => $seller->id, 'status' => 'not_sold']);
        Passport::actingAs($buyer, ['*']);
        return [$buyer, $seller, $nick];
    }

    public function test_purchase_returns_canonical_balance_and_retry_does_not_charge_twice(): void
    {
        [$buyer, $seller, $nick] = $this->fixture();
        $first = $this->postJson('/api/purchase', ['productId' => $nick->id])->assertOk()
            ->assertJsonPath('transaction.remaining_balance', 300000)->assertJsonPath('nick.password', 'fixture-password');
        $this->postJson('/api/purchase', ['productId' => $nick->id])->assertOk()
            ->assertJsonPath('transaction.order_id', $first->json('transaction.order_id'));
        $this->assertEquals(300000, $buyer->fresh()->balance);
        $this->assertEquals(300000, $seller->fresh()->balance);
        $this->assertSame(1, NickOrder::where('nick_id', $nick->id)->count());
        $this->assertDatabaseHas('transactions', ['user_id' => $buyer->id, 'balance_before' => 500000, 'balance_after' => 300000]);
        Passport::actingAs(User::factory()->create(['balance' => 900000]), ['*']);
        $this->postJson('/api/purchase', ['productId' => $nick->id])->assertNotFound();
    }

    public function test_stale_request_user_does_not_corrupt_ledger_or_response(): void
    {
        [$buyer, , $nick] = $this->fixture();
        DB::table('users')->where('id', $buyer->id)->update(['balance' => 350000]);
        $this->postJson('/api/purchase', ['productId' => $nick->id])->assertOk()->assertJsonPath('transaction.remaining_balance', 150000);
        $this->assertDatabaseHas('transactions', ['user_id' => $buyer->id, 'balance_before' => 350000, 'balance_after' => 150000]);
    }

    public function test_insufficient_funds_close_transaction_without_mutation(): void
    {
        [$buyer, $seller, $nick] = $this->fixture();
        DB::table('users')->where('id', $buyer->id)->update(['balance' => 100]);
        $level = DB::transactionLevel();
        $this->postJson('/api/purchase', ['productId' => $nick->id])->assertStatus(400);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertEquals(100000, $seller->fresh()->balance);
        $this->assertSame('not_sold', $nick->fresh()->status);
        $this->assertDatabaseCount('nick_orders', 0);
    }

    public function test_bad_credentials_fail_before_charging(): void
    {
        [$buyer, $seller, $nick] = $this->fixture();
        DB::table('nicks')->where('id', $nick->id)->update(['account_password' => 'invalid']);
        $this->postJson('/api/purchase', ['productId' => $nick->id])->assertStatus(500);
        $this->assertEquals(500000, $buyer->fresh()->balance);
        $this->assertEquals(100000, $seller->fresh()->balance);
        $this->assertDatabaseCount('nick_orders', 0);
    }

    public function test_post_purchase_cache_failure_does_not_report_failed_payment(): void
    {
        [$buyer, , $nick] = $this->fixture();
        $cache = \Mockery::mock(Cache::getFacadeRoot());
        $cache->shouldReceive('forever')->andThrow(new \RuntimeException('cache unavailable'));
        Cache::swap($cache);
        $this->postJson('/api/purchase', ['productId' => $nick->id])->assertOk()->assertJsonPath('transaction.remaining_balance', 300000);
        $this->assertEquals(300000, $buyer->fresh()->balance);
        $this->assertDatabaseCount('nick_orders', 1);
    }
}
