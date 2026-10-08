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
        User::factory()->create(['id'=>1,'balance'=>1000]);
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
        $one=$this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'attempt-1'])->assertOk();
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'attempt-1'])->assertOk()
            ->assertJsonPath('nick.id',$one->json('nick.id'));
        $this->assertEquals(300,$buyer->fresh()->balance);
        $this->assertEquals(100,$seller->fresh()->balance);
        $this->assertEquals(1200,User::findOrFail(1)->balance);
        $this->assertSame(1,RandomOrder::count());
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'attempt-2'])->assertOk();
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'attempt-3'])->assertStatus(400);
        $this->assertEquals(100,$buyer->fresh()->balance);
        $this->assertSame(2,RandomOrder::count());
    }
    public function test_missing_revenue_account_does_not_charge_or_consume_stock(): void {
        [$buyer,,$box,$url]=$this->fixture();
        DB::table('users')->where('id',1)->delete();
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'missing-admin'])->assertStatus(409);
        $this->assertEquals(500,$buyer->fresh()->balance);
        $this->assertSame(0,RandomOrder::count());
        $this->assertSame(3,RandomNick::where('status','available')->count());
    }
    public function test_revenue_limit_rejects_purchase_atomically(): void {
        [$buyer,,$box,$url]=$this->fixture();
        DB::table('users')->where('id',1)->update(['balance'=>\App\Services\TransactionService::MAX_BALANCE]);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'full-admin'])->assertStatus(409);
        $this->assertEquals(500,$buyer->fresh()->balance);
        $this->assertSame(0,RandomOrder::count());
        $this->assertSame(3,RandomNick::where('status','available')->count());
    }
    public function test_admin_own_purchase_has_balanced_debit_and_credit_and_replays_once(): void {
        [,, $box,$url]=$this->fixture();
        $box->update(['win_rate'=>0]);
        Passport::actingAs(User::findOrFail(1),['*']);
        $request=['draw_version'=>2,'idempotency_key'=>'admin-open'];
        $one=$this->postJson($url.'/buy',$request)->assertOk()->assertJsonPath('transaction.remaining_balance',1000);
        $this->postJson($url.'/buy',$request)->assertOk()->assertJsonPath('transaction.order_id',$one->json('transaction.order_id'));
        $this->assertEquals(1000,User::findOrFail(1)->balance);
        $this->assertDatabaseHas('transactions',['user_id'=>1,'type'=>'buy_random','amount'=>-200,'balance_before'=>1000,'balance_after'=>800]);
        $this->assertDatabaseHas('transactions',['user_id'=>1,'type'=>'sell_random','amount'=>200,'balance_before'=>800,'balance_after'=>1000]);
        $this->assertSame(2,DB::table('transactions')->where('user_id',1)->count());
    }
    public function test_selected_slot_retry_conflict_and_stale_balance(): void {
        [$buyer,,$box,$url]=$this->fixture();
        DB::table('users')->where('id',$buyer->id)->update(['balance'=>350]);
        $request = ['draw_version'=>2,'idempotency_key'=>'slot','selected_slot'=>7];
        $one = $this->postJson($url.'/buy',$request)->assertOk()
            ->assertJsonPath('selected_slot',7)->assertJsonPath('transaction.remaining_balance',150);
        $this->postJson($url.'/buy',$request)->assertOk()->assertJsonPath('transaction.order_id',$one->json('transaction.order_id'));
        $request['selected_slot']=8;
        $this->postJson($url.'/buy',$request)->assertStatus(409);
        $this->assertDatabaseHas('transactions',['user_id'=>$buyer->id,'balance_before'=>350,'balance_after'=>150]);
        $this->assertSame(1,RandomOrder::count());
        $this->postJson($url.'/buy-nick/1',['idempotency_key'=>'legacy'])->assertStatus(409);
        $this->postJson($url.'/buy',['idempotency_key'=>'old-client'])->assertStatus(422);
        $this->postJson($url.'/buy',['draw_version'=>2])->assertStatus(422);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'invalid','selected_slot'=>21])->assertStatus(422);
        $this->assertSame(1,RandomOrder::count());
    }
    public function test_zero_rate_loses_without_consuming_stock_and_replay_keeps_outcome(): void {
        [$buyer,$seller,$box,$url]=$this->fixture();
        $box->update(['win_rate'=>0]);
        $request=['draw_version'=>2,'idempotency_key'=>'lose','selected_slot'=>1];
        $one=$this->postJson($url.'/buy',$request)->assertOk()->assertJsonPath('result','lose')->assertJsonPath('nick',null)->assertJsonPath('message','Đã xịt');
        $box->update(['win_rate'=>100]);
        $this->postJson($url.'/buy',$request)->assertOk()->assertJsonPath('result','lose')->assertJsonPath('transaction.order_id',$one->json('transaction.order_id'));
        $this->assertEquals(300,$buyer->fresh()->balance);
        $this->assertEquals(100,$seller->fresh()->balance);
        $this->assertEquals(1200,User::findOrFail(1)->balance);
        $this->assertSame(3,RandomNick::where('status','available')->count());
        $this->assertDatabaseHas('random_orders',['result'=>'lose','lose_reason'=>'probability','win_rate_snapshot'=>0,'random_nick_id'=>null,'random_box_id'=>$box->id]);
        $this->getJson('/api/profile/random')->assertOk()->assertJsonPath('data.0.result','lose')->assertJsonPath('data.0.nick',null)->assertJsonPath('data.0.box.id',$box->id);
        $this->getJson('/api/profile/random?status=lose')->assertOk()->assertJsonPath('meta.total',1);
    }
    public function test_empty_stock_charges_a_loss_even_at_one_hundred_percent(): void {
        [$buyer,$seller,$box,$url]=$this->fixture();
        RandomNick::query()->update(['status'=>'taken']);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'empty'])->assertOk()->assertJsonPath('result','lose')->assertJsonPath('nick',null);
        $this->assertEquals(300,$buyer->fresh()->balance);
        $this->assertEquals(100,$seller->fresh()->balance);
        $this->assertEquals(1200,User::findOrFail(1)->balance);
        $this->assertDatabaseHas('random_orders',['result'=>'lose','lose_reason'=>'empty_stock','random_box_id'=>$box->id]);
        $this->getJson($url)->assertOk()->assertJsonCount(20,'data.data')->assertJsonPath('data.data.0.account','');
    }
    public function test_last_account_is_not_delivered_twice(): void {
        [$buyer,,$box,$url]=$this->fixture();
        RandomNick::where('id','<>',RandomNick::min('id'))->update(['status'=>'taken']);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'first'])->assertOk()->assertJsonPath('result','win');
        Passport::actingAs(User::factory()->create(['balance'=>500]), ['*']);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'second'])->assertOk()->assertJsonPath('result','lose');
        $this->assertSame(1,RandomOrder::whereNotNull('random_nick_id')->count());
    }
    public function test_insufficient_balance_does_not_create_a_loss(): void {
        [$buyer,,$box,$url]=$this->fixture();
        $box->update(['win_rate'=>0]);
        DB::table('users')->where('id',$buyer->id)->update(['balance'=>100]);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'poor'])->assertStatus(400);
        $this->assertSame(0,RandomOrder::count());
        $this->assertEquals(100,$buyer->fresh()->balance);
    }
    public function test_fractional_rate_boundary_and_losing_draw_skips_stock_query(): void {
        [$buyer,,$box,$url]=$this->fixture();
        $box->update(['win_rate'=>1.25]);
        $service=\Mockery::mock(\App\Services\RandomPurchaseService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('draw')->twice()->andReturn(125,126);
        $this->app->instance(\App\Services\RandomPurchaseService::class,$service);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'boundary-win'])->assertOk()->assertJsonPath('result','win');
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'boundary-lose'])->assertOk()->assertJsonPath('result','lose');
        $queries=DB::getQueryLog(); DB::disableQueryLog();
        foreach ($queries as $query) $this->assertStringNotContainsString('random_nicks', $query['query']);
        $this->assertEquals(100,$buyer->fresh()->balance);
    }
    public function test_migration_preserves_old_orders_and_backfills_box(): void {
        [$buyer,,$box]=$this->fixture();
        $migration=require database_path('migrations/2026_10_07_120000_add_random_win_results.php');
        $migration->down();
        $nick=RandomNick::first();
        $id=DB::table('random_orders')->insertGetId(['user_id'=>$buyer->id,'random_nick_id'=>$nick->id,'price'=>200,'created_at'=>now(),'updated_at'=>now()]);
        $migration->up();
        $this->assertDatabaseHas('random_orders',['id'=>$id,'random_box_id'=>$box->id,'result'=>'win','win_rate_snapshot'=>100,'random_nick_id'=>$nick->id]);
        $this->assertDatabaseHas('random_boxes',['id'=>$box->id,'win_rate'=>100]);
        $nick->delete();
        $this->getJson('/api/profile/random')->assertOk()->assertJsonPath('data.0.nick.account',$nick->account);
    }
    public function test_admin_rate_validation_accepts_zero_and_rejects_invalid_precision(): void {
        [$buyer,,$box]=$this->fixture();
        foreach ([new \App\Http\Requests\RandomBox\StoreRandomBoxRequest(),new \App\Http\Requests\RandomBox\UpdateRandomBoxRequest()] as $request) {
            $rules=['win_rate'=>$request->rules()['win_rate']];
            foreach ([0,100,1.25] as $rate) $this->assertFalse(\Illuminate\Support\Facades\Validator::make(['win_rate'=>$rate],$rules)->fails());
            foreach ([-1,100.01,1.234,'invalid'] as $rate) $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['win_rate'=>$rate],$rules)->fails());
        }
    }
    public function test_customer_endpoints_do_not_expose_random_configuration(): void {
        [$buyer,,$box,$url]=$this->fixture();
        $box->update(['win_rate'=>0]);
        $category=Category::findOrFail($box->category_id);
        $this->getJson('/api/categories/'.$category->slug.'/nicks')->assertOk()
            ->assertJsonPath('data.data.0.id',$box->id)
            ->assertJsonMissingPath('data.data.0.win_rate')
            ->assertJsonMissingPath('data.data.0.available_nicks_count');
        $this->getJson($url)->assertOk()->assertJsonPath('box.id',$box->id)
            ->assertJsonMissingPath('box.win_rate')->assertJsonMissingPath('box.available_nicks_count');
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'private-config'])->assertOk()
            ->assertJsonPath('result','lose')->assertJsonMissingPath('win_rate_snapshot');
        $this->getJson('/api/profile/random')->assertOk()->assertJsonMissingPath('data.0.win_rate_snapshot');
        $this->assertDatabaseHas('random_boxes',['id'=>$box->id,'win_rate'=>0]);
        $this->assertDatabaseHas('random_orders',['random_box_id'=>$box->id,'win_rate_snapshot'=>0]);
    }
    public function test_post_commit_cache_failure_does_not_report_failed_payment(): void {
        [$buyer,,$box,$url]=$this->fixture();
        $cache=\Mockery::mock(Cache::getFacadeRoot());
        $cache->shouldReceive('forever')->andThrow(new \RuntimeException('cache unavailable'));
        Cache::swap($cache);
        $this->postJson($url.'/buy',['draw_version'=>2,'idempotency_key'=>'cache-failure'])->assertOk();
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
