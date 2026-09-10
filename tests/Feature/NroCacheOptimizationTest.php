<?php
namespace Tests\Feature;
use App\Support\ApiCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NroCacheOptimizationTest extends TestCase
{
    public function test_invalidation_during_production_cannot_resurrect_an_old_group(): void
    {
        Cache::flush();$calls=0;
        $first=ApiCache::remember('test-catalog','page',60,function () use(&$calls) {
            $calls++;ApiCache::clearGroup('test-catalog');return 'old-read';
        });
        $this->assertSame('old-read',$first);
        $this->assertSame('fresh',ApiCache::remember('test-catalog','page',60,function () use(&$calls) {$calls++;return 'fresh';}));
        $this->assertSame('fresh',ApiCache::remember('test-catalog','page',60,function () use(&$calls) {$calls++;return 'wrong';}));
        $this->assertSame(2,$calls);
    }
    public function test_groups_are_independent_and_null_values_are_cached(): void
    {
        Cache::flush();$calls=0;
        $producer=function() use(&$calls) {$calls++;return null;};
        ApiCache::remember('a','same',60,$producer);ApiCache::remember('a','same',60,$producer);
        $this->assertSame(1,$calls);
        $this->assertSame('B',ApiCache::remember('b','same',60,fn()=>'B'));
        ApiCache::clearGroup('a');ApiCache::remember('a','same',60,$producer);
        $this->assertSame(2,$calls);
        $this->assertSame('B',ApiCache::remember('b','same',60,fn()=>'wrong'));
    }
}
