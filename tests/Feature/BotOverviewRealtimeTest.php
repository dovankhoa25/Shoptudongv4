<?php

namespace Tests\Feature;

use App\Events\TradingBotOverviewUpdated;
use App\Models\{Bot, GemBot, Server};
use App\Services\TradingV3\TradingActivityBroadcast;
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event, Log};
use Tests\TestCase;

use function Illuminate\Support\defer;

class BotOverviewRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_invalidations_coalesce_after_cache_eviction_and_use_existing_channel(): void
    {
        Event::fake([TradingBotOverviewUpdated::class]);
        $this->assertSame('old', ApiCache::remember('public:bots', 'test', 180, fn () => 'old'));
        ApiCache::clearGroups(['public:bots', 'public:gembot']);
        ApiCache::clearGroup('public:bots');
        Event::assertNotDispatched(TradingBotOverviewUpdated::class);
        $this->assertSame('fresh', ApiCache::remember('public:bots', 'test', 180, fn () => 'fresh'));
        defer()->invoke();
        Event::assertDispatchedTimes(TradingBotOverviewUpdated::class, 1);
        Event::assertDispatched(TradingBotOverviewUpdated::class, function ($event) {
            $this->assertSame('Trading.Bots', $event->broadcastOn()[0]->name);
            $this->assertSame('TradingBotOverviewUpdated', $event->broadcastAs());
            $this->assertSame(['refresh' => true], $event->broadcastWith());
            return true;
        });
        defer()->invoke();
        Event::assertDispatchedTimes(TradingBotOverviewUpdated::class, 1);
    }

    public function test_commit_notifies_but_rollback_and_unrelated_cache_groups_do_not(): void
    {
        Event::fake([TradingBotOverviewUpdated::class]);
        DB::beginTransaction();
        ApiCache::clearGroup('public:bots');
        defer()->invoke();
        Event::assertNotDispatched(TradingBotOverviewUpdated::class);
        DB::rollBack();
        defer()->invoke();
        ApiCache::clearGroup('public:nick');
        defer()->invoke();
        Event::assertNotDispatched(TradingBotOverviewUpdated::class);
        DB::beginTransaction();
        ApiCache::clearGroup('public:gembot');
        DB::commit();
        defer()->invoke();
        Event::assertDispatchedTimes(TradingBotOverviewUpdated::class, 1);
    }

    public function test_radar_and_ticket_changes_notify_but_credentials_and_no_op_writes_do_not(): void
    {
        $server = Server::create(['name' => 'One', 'status' => true]);
        $attributes = ['name' => 'Bot', 'account_name' => 'private', 'account_password' => 'secret',
            'server_id' => $server->id, 'server_game_id' => 1, 'status' => true,
            'map_id' => '1', 'map_name' => 'Kame', 'area_number' => '0'];
        $bot = Bot::create([...$attributes, 'type' => 'selling_sub']);
        $gem = GemBot::create($attributes);
        defer()->invoke();
        Event::fake([TradingBotOverviewUpdated::class]);
        $bot->update(['account_password' => 'changed']);
        $bot->save();
        defer()->invoke();
        Event::assertNotDispatched(TradingBotOverviewUpdated::class);
        $bot->update(['radar_qty' => 6, 'bag_capacity' => 20, 'bag_free_slots' => 14]);
        defer()->invoke();
        Event::assertDispatchedTimes(TradingBotOverviewUpdated::class, 1);
        $gem->update(['gift_ticket_qty' => 5]);
        defer()->invoke();
        Event::assertDispatchedTimes(TradingBotOverviewUpdated::class, 2);
        $gem->update(['consign_item_qty' => 3]);
        defer()->invoke();
        Event::assertDispatchedTimes(TradingBotOverviewUpdated::class, 3);
        $bot->delete();
        defer()->invoke();
        Event::assertDispatchedTimes(TradingBotOverviewUpdated::class, 4);
    }

    public function test_transport_failure_does_not_fail_the_write_or_cache_invalidation(): void
    {
        $this->mock(TradingActivityBroadcast::class)->shouldReceive('publishOverviewChanged')->once()
            ->andThrow(new \RuntimeException('Offline'));
        Log::spy();
        ApiCache::remember('public:bots', 'test', 180, fn () => 'old');
        ApiCache::clearGroup('public:bots');
        defer()->invoke();
        $this->assertSame('fresh', ApiCache::remember('public:bots', 'test', 180, fn () => 'fresh'));
        Log::shouldHaveReceived('warning')->with('Public bot overview realtime delivery failed.')->once();
    }
}
