<?php
namespace Tests\Feature;

use App\Models\{User, Category, GameType, RandomBox, RandomNick, RandomOrder};
use App\Services\UserBalanceSnapshot;
use Illuminate\Support\Facades\{DB, Cache};
use Laravel\Passport\Passport;
use Tests\TestCase;

class RandomPurchaseConsistencyTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
    }
    private function fixture(): array {
        $buyer = User::factory()->create(['balance' => 500]);
        $seller = User::factory()->create(['balance' => 100]);
        $game = GameType::create(['name'=>'Test']);
        $cat = Category::create(['game_type_id'=>$game->id,'name'=>'Random test','template'=>'random','is_public'=>true,'status'=>'active']);
        $box = RandomBox::create(['category_id'=>$cat->id,'name'=>'Fixture','price'=>200,'is_public'=>true]);
        foreach(range(1,3) as $n) RandomNick::create(['random_box_id'=>$box->id,'user_id'=>$seller->id,'account'=>'fixture-'.$n,'password'=>'fake','status'=>'available']);
        Passport::actingAs($buyer, ['*']);
        return [$buyer, $seller, $box, '/api/categories/'.$cat->slug.'/random-boxes/'.$box->id];
    }
    public function test_random_retry_replays_order_without_buying_another_nick(): void {
        [$buyer,$seller,$box,$url]=$this->fixture();
        $one=$this->postJson($url.'/buy',['idempotency_key'=>'attempt-1'])->assertOk();
        $this->postJson($url.'/buy',['idempotency_key'=>'attempt-1'])->assertOk()
            ->assertJsonPath('nick.id',$one->json('nick.id'));
        $this->assertEquals(300,$buyer->fresh()->balance);
        $this->assertEquals(300,$seller->fresh()->balance);
        $this->assertSame(1,RandomOrder::count());
        $this->postJson($url.'/buy',['idempotency_key'=>'attempt-2'])->assertOk();
        $this->postJson($url.'/buy',['idempotency_key'=>'attempt-3'])->assertStatus(400);
        $this->assertEquals(100,$buyer->fresh()->balance);
        $this->assertSame(2,RandomOrder::count());
    }
    public function test_specific_retry_and_key_conflict_and_stale_balance(): void {
        [$buyer,$seller,$box,$url]=$this->fixture();
        $nicks=RandomNick::where('random_box_id',$box->id)->get();
        DB::table('users')->where('id',$buyer->id)->update(['balance'=>350]);
        $this->postJson($url.'/buy-nick/'.$nicks[0]->id,['idempotency_key'=>'specific'])->assertOk()
            ->assertJsonPath('transaction.remaining_balance',150);
        $this->postJson($url.'/buy-nick/'.$nicks[0]->id)->assertOk();
        $this->postJson($url.'/buy-nick/'.$nicks[1]->id,['idempotency_key'=>'specific'])->assertStatus(409);
        $this->assertDatabaseHas('transactions',['user_id'=>$buyer->id,'balance_before'=>350,'balance_after'=>150]);
        $this->assertSame(1,RandomOrder::count());
        Passport::actingAs(User::factory()->create(['balance'=>900]), ['*']);
        $this->postJson($url.'/buy-nick/'.$nicks[0]->id)->assertStatus(409);
    }
    public function test_post_commit_cache_failure_does_not_report_failed_payment(): void {
        [$buyer,,$box,$url]=$this->fixture();
        $cache=\Mockery::mock(Cache::getFacadeRoot());
        $cache->shouldReceive('forever')->andThrow(new \RuntimeException('cache unavailable'));
        Cache::swap($cache);
        $this->postJson($url.'/buy',['idempotency_key'=>'cache-failure'])->assertOk();
        $this->assertEquals(300,$buyer->fresh()->balance);
        $this->assertSame(1,RandomOrder::count());
    }
    public function test_profile_fast_path_uses_one_read_and_repairs_changed_balance(): void {
        $user=User::factory()->create(['balance'=>123]);
        $initial=UserBalanceSnapshot::forProfile($user->id);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame($initial,UserBalanceSnapshot::forProfile($user->id));
        $queries=DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(1,$queries);
        $this->assertStringNotContainsString('for update',strtolower($queries[0]['query']));
        DB::table('users')->where('id',$user->id)->update(['balance'=>90]);
        $next=UserBalanceSnapshot::forProfile($user->id);
        $this->assertSame(90,$next['balance']);
        $this->assertGreaterThan($initial['balance_revision'],$next['balance_revision']);
        Passport::actingAs($user,['profile:read']);
        $this->getJson('/api/me/balance')->assertOk()->assertExactJson($next);
    }
}
