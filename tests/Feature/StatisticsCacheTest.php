<?php
namespace Tests\Feature;

use App\Http\Controllers\Admin\AnalyticsController;
use App\Models\{Category, GameType, GoldTransaction, Server, User, WithdrawalRequest};
use App\Support\{TradingStatistics, WithdrawalStatistics};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StatisticsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_trading_totals_keep_filters_scopes_and_today_boundaries_in_one_query(): void
    {
        $server = Server::create(['name' => 'Server']);
        foreach ([
            [1, 'completed', 10, today()], [1, 'pending', 100, today()],
            [1, 'completed', 20, today()->subSecond()], [2, 'completed', 999, today()],
            [1, 'completed', 999, today()->addDay()],
        ] as [$user, $status, $amount, $date]) {
            DB::table('gold_transactions')->insert(['user_id' => $user, 'server_id' => $server->id, 'type' => 'order',
                'character_name' => 'test', 'price_at_transaction' => 1, 'amount_vnd' => $amount, 'gold_qty' => 5,
                'status' => $status, 'created_at' => $date]);
        }
        $query = GoldTransaction::query()->where('created_at', '<', today()->addDay())
            ->withGlobalScope('test-owner', fn ($builder) => $builder->where('user_id', 1));
        DB::enableQueryLog();
        $data = TradingStatistics::read($query, 'gold');
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(3, $data['total_orders']);
        $this->assertSame(2, $data['completed_orders']);
        $this->assertSame(2, $data['today_orders']);
        $this->assertEquals(30, $data['total_revenue']);
        $this->assertEquals(10, $data['today_revenue']);
        $empty = TradingStatistics::read(GoldTransaction::whereRaw('1 = 0'), 'gold');
        $this->assertSame(0, $empty['total_orders']);
        $this->assertEquals(0, $empty['total_revenue']);
    }

    public function test_withdrawal_totals_apply_owner_scope_without_caching_money_state(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $this->app->instance('request', Request::create('/admin/withdrawals'));
        foreach ([[$user, 'paid', 100], [$user, 'pending', 200], [$other, 'paid', 999]] as [$owner, $status, $amount]) {
            WithdrawalRequest::withoutEvents(fn () => WithdrawalRequest::create([
                'user_id' => $owner->id, 'amount' => $amount, 'fee' => 5, 'net_amount' => $amount - 5,
                'status' => $status, 'bank_name' => 'test', 'bank_account_number' => 'test', 'bank_account_name' => 'test',
            ]));
        }
        DB::enableQueryLog();
        $data = WithdrawalStatistics::read(WithdrawalRequest::query());
        $queries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'withdrawal_requests'));
        $this->assertCount(1, $queries);
        DB::disableQueryLog();
        $this->assertSame(2, $data['total_requests']);
        $this->assertEquals(300, $data['total_amount']);
        $this->assertEquals(95, $data['paid_amount']);
        $this->assertEquals(200, $data['pending_amount']);
    }

    public function test_analytics_cache_separates_sellers_and_refreshes_after_stats_command(): void
    {
        Cache::flush();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        Role::findOrCreate('ctv', 'web');
        $seller->assignRole('ctv');
        $other->assignRole('ctv');
        $game = GameType::create(['name' => 'Game']);
        $category = Category::create(['name' => 'Category', 'game_type_id' => $game->id]);
        foreach ([[$seller->id, 100], [$other->id, 900]] as [$id, $amount]) {
            DB::table('seller_category_stats')->insert(['seller_id' => $id, 'category_id' => $category->id,
                'stat_date' => today()->toDateString(), 'nick_total_revenue' => $amount]);
        }
        $read = function ($user) {
            $request = Request::create('/admin/analytics');
            $request->headers->set('X-Inertia', 'true');
            $request->setUserResolver(fn () => $user);
            return app(AnalyticsController::class)->index($request)->toResponse($request)->getData(true)['props']['analytics'];
        };
        $this->assertEquals(100, $read($seller)['seller_total']['nick_total_revenue']);
        $this->assertEquals(900, $read($other)['seller_total']['nick_total_revenue']);
        $category->update(['name' => 'Changed']);
        $this->assertSame('Changed', $read($seller)['categories'][0]['category_name']);
        $this->artisan('stats:compute')->assertSuccessful();
        $this->assertSame([], $read($seller)['categories']);
    }
}
