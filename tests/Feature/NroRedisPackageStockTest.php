<?php
namespace Tests\Feature;
use App\Support\ApiCache;
use Illuminate\Support\Facades\Cache;

/** Opt-in isolated Redis; never uses a deployment's configured host or credentials. */
class NroRedisPackageStockTest extends NroPackageStockTest
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NRO_TEST_REDIS_PORT') !== '16389') $this->markTestSkipped('Run with isolated Redis on localhost:16389.');
        config(['cache.default'=>'redis','cache.prefix'=>'nro-test-'.bin2hex(random_bytes(8)).':',
            'database.redis.client'=>extension_loaded('redis') ? 'phpredis' : 'predis','database.redis.options.prefix'=>'isolated:',
            'database.redis.cache'=>['host'=>'127.0.0.1','port'=>16389,'database'=>1,'password'=>null,'url'=>null],
            'database.redis.default'=>['host'=>'127.0.0.1','port'=>16389,'database'=>0,'password'=>null,'url'=>null]]);
        app('redis')->purge('cache'); app('redis')->purge('default'); app('cache')->forgetDriver('redis');
    }
    public function test_redis_group_generation_prevents_inflight_stale_refill(): void
    {
        $calls=0;
        $this->assertSame('old',ApiCache::remember('redis-race','page',60,function() use(&$calls) {
            $calls++; ApiCache::clearGroup('redis-race'); return 'old';
        }));
        $this->assertSame('new',ApiCache::remember('redis-race','page',60,function() use(&$calls) { $calls++; return 'new'; }));
        $this->assertSame('new',ApiCache::remember('redis-race','page',60,fn()=>'wrong'));
        $this->assertSame(2,$calls);
    }
}
