<?php
namespace Tests\Feature;

use App\Jobs\PublishTradingBotActivities;
use Illuminate\Support\Facades\{Cache,Redis};
use Tests\TestCase;

class TradingActivityRedisCommandTest extends TestCase
{
    private function payload(string $name=PublishTradingBotActivities::class): string { return json_encode(['data'=>['commandName'=>$name,'command'=>'never-unserialize']]); }
    private function client(int $pending=1,int $reserved=0,int $delayed=0): array
    {
        config(['trading_v3.activity_queue' => 'trading-activity', 'trading_v3.activity_queue_connection' => 'redis',
            'trading_v3.activity_dispatch' => 'after_response', 'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'default']);
        $client = \Mockery::mock();$state=(object)compact('pending','reserved','delayed');$state->notify=1;
        Redis::shouldReceive('connection')->with('default')->andReturn($client);
        $client->shouldReceive('info')->with('memory')->once()->andReturn(['used_memory' => 100, 'maxmemory' => 100, 'maxmemory_policy' => 'noeviction']);
        $client->shouldReceive('llen')->with('queues:trading-activity')->andReturnUsing(fn()=>$state->pending);
        $client->shouldReceive('llen')->with('queues:trading-activity:notify')->andReturnUsing(fn()=>$state->notify);
        $client->shouldReceive('zcard')->with('queues:trading-activity:reserved')->andReturnUsing(fn()=>$state->reserved);
        $client->shouldReceive('zcard')->with('queues:trading-activity:delayed')->andReturnUsing(fn()=>$state->delayed);
        $client->shouldNotReceive('multi');$client->shouldNotReceive('watch');$client->shouldNotReceive('exec');$client->shouldNotReceive('eval');
        return [$client,$state];
    }
    public function test_default_diagnostic_is_read_only(): void
    {
        [$client]=$this->client();$client->shouldNotReceive('del');$client->shouldNotReceive('lrange');$client->shouldNotReceive('lrem');
        $this->artisan('trading:activity-redis')->assertSuccessful();
    }
    public function test_cleanup_removes_exact_verified_payloads_without_a_transaction(): void
    {
        [$client,$state]=$this->client();$payload=$this->payload();
        $client->shouldReceive('lrange')->with('queues:trading-activity',0,9)->once()->andReturn([$payload]);
        $client->shouldReceive('lrange')->with('queues:trading-activity',0,0)->once()->andReturn([$payload]);
        $client->shouldReceive('lrem')->with('queues:trading-activity',1,$payload)->once()->andReturnUsing(function()use($state){$state->pending=0;return 1;});
        $client->shouldReceive('del')->with('queues:trading-activity:notify')->once()->andReturn(1);
        $this->artisan('trading:activity-redis', ['--clear-backlog' => true])->assertSuccessful();
    }
    public function test_unknown_job_prevents_all_cleanup(): void
    {
        [$client]=$this->client();
        $client->shouldReceive('lrange')->once()->andReturn([json_encode(['data' => ['commandName' => 'FinancialReceiptJob']])]);
        $client->shouldNotReceive('del');$client->shouldNotReceive('lrem');$client->shouldNotReceive('zrem');
        $this->artisan('trading:activity-redis', ['--clear-backlog' => true])->assertFailed();
    }
    public function test_default_or_shared_queue_is_never_read_or_deleted(): void
    {
        config(['trading_v3.activity_queue' => 'default', 'trading_v3.activity_queue_connection' => 'redis']);
        Redis::shouldReceive('connection')->never();
        $this->artisan('trading:activity-redis', ['--clear-backlog' => true])->assertFailed();
    }
    public function test_cleanup_refuses_a_producer_that_still_uses_queue_mode(): void
    {
        [$client]=$this->client();config(['trading_v3.activity_dispatch'=>'queue']);$client->shouldNotReceive('del');$client->shouldNotReceive('lrem');
        $this->artisan('trading:activity-redis', ['--clear-backlog' => true])->assertFailed();
    }
    public function test_same_count_foreign_replacement_is_preserved(): void
    {
        [$client]=$this->client();$client->shouldReceive('lrange')->with('queues:trading-activity',0,9)->once()->andReturn([$this->payload()]);
        $client->shouldReceive('lrange')->with('queues:trading-activity',0,0)->once()->andReturn([$this->payload('FinancialReceiptJob')]);
        $client->shouldNotReceive('lrem');$client->shouldNotReceive('del');
        $this->artisan('trading:activity-redis',['--clear-backlog'=>true])->assertFailed();
    }

    public function test_reserved_and_delayed_use_only_exact_verified_zrem_members(): void
    {
        [$client,$state]=$this->client(0,1,1);$payload=$this->payload();
        foreach(['reserved','delayed']as$name){$key='queues:trading-activity:'.$name;
            $client->shouldReceive('zrange')->with($key,0,9)->once()->andReturn([$payload]);
            $client->shouldReceive('zrange')->with($key,0,0)->once()->andReturn([$payload]);
            $client->shouldReceive('zrem')->with($key,$payload)->once()->andReturnUsing(function()use($state,$name){$state->{$name}=0;return 1;});
        }
        $client->shouldReceive('del')->with('queues:trading-activity:notify')->once()->andReturn(1);
        $this->artisan('trading:activity-redis',['--clear-backlog'=>true])->assertSuccessful();
    }

    public function test_replacement_after_last_read_is_not_removed_and_notify_is_kept(): void
    {
        [$client]=$this->client();$payload=$this->payload();$client->shouldReceive('lrange')->twice()->andReturn([$payload]);
        $client->shouldReceive('lrem')->with('queues:trading-activity',1,$payload)->once()->andReturn(0);$client->shouldNotReceive('del');
        $this->artisan('trading:activity-redis',['--clear-backlog'=>true])->assertFailed();
    }

    public function test_legacy_activity_cache_cleanup_matches_both_prefixes_and_only_bot_keys(): void
    {
        $this->client(0);config(['cache.stores.redis.driver'=>'redis']);
        $store=\Mockery::mock(\Illuminate\Cache\RedisStore::class);$repository=\Mockery::mock();$repository->shouldReceive('getStore')->once()->andReturn($store);
        Cache::shouldReceive('store')->with('redis')->once()->andReturn($repository);
        $connection=\Mockery::mock();$client=\Mockery::mock(\Predis\Client::class);
        $store->shouldReceive('getPrefix')->once()->andReturn('cache_');$store->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('client')->once()->andReturn($client);$client->shouldReceive('getOptions')->once()->andReturn((object)['prefix'=>new \Predis\Command\Processor\KeyPrefixProcessor('backend_')]);
        $client->shouldReceive('executeRaw')->with(['SCAN','0','MATCH','backend_cache_trading-v3:activity:*','COUNT','100'])->once()->andReturn(['0',[
            'backend_cache_trading-v3:activity:bot:3','backend_cache_trading-v3:activity:gem_bot:9983',
            'backend_cache_trading-v3:activity:nonce','backend_cache_trading-v3:activity:order:10','backend_cache_trading-v3:activity:bot:0',
            'another_cache_trading-v3:activity:bot:3','backend_cache_trading-v3:nonce:123']]);
        $connection->shouldReceive('del')->with('cache_trading-v3:activity:bot:3')->once()->andReturn(1);
        $connection->shouldReceive('del')->with('cache_trading-v3:activity:gem_bot:9983')->once()->andReturn(1);
        $connection->shouldReceive('del')->with('cache_trading-v3:gem-timeout-policy')->once()->andReturn(1);
        $this->artisan('trading:activity-redis',['--clear-activity-cache'=>true])->assertSuccessful();
    }

    public function test_legacy_cache_cleanup_refuses_non_redis_store(): void
    {
        $this->client(0);config(['cache.stores.array.driver'=>'array']);Cache::shouldReceive('store')->never();
        $this->artisan('trading:activity-redis',['--clear-activity-cache'=>true,'--cache-store'=>'array'])->assertFailed();
    }
}
