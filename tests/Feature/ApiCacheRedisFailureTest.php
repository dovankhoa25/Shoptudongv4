<?php

namespace Tests\Feature;

use App\Support\ApiCache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Log, Schema};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Connection\{ConnectionException, NodeConnectionInterface};
use Predis\Response\ServerException;
use Tests\TestCase;

class ApiCacheRedisFailureTest extends TestCase
{
    private const GROUP='optional-redis-failure';
    private const KEY='page';

    private function generationKey(): string {return ApiCache::key('generation',self::GROUP);}
    private function valueKey(): string {return ApiCache::key('value',self::GROUP,hash('sha256',self::KEY));}
    private function oom(): ServerException {return new ServerException('OOM command not allowed when used memory > maxmemory. redis://credential-sentinel@host');}
    private function repository(): \Mockery\MockInterface
    {
        $cache=Mockery::mock();Cache::swap($cache);return $cache;
    }
    private function miss(\Mockery\MockInterface $cache): void
    {
        $cache->shouldReceive('get')->with($this->generationKey())->once()->andReturn('generation-1');
        $cache->shouldReceive('get')->with($this->valueKey())->twice()->andReturnNull();
    }
    private function lock(\Mockery\MockInterface $cache,?callable $block=null): void
    {
        $lock=Mockery::mock();
        $cache->shouldReceive('lock')->with($this->valueKey().':build',30)->once()->andReturn($lock);
        $lock->shouldReceive('block')->with(5,Mockery::type('Closure'))->once()->andReturnUsing($block??fn($seconds,$load)=>$load());
    }
    public static function failureStages(): array
    {
        $stages=['generation-read','generation-add','generation-reread','value-read','lock-create','lock-acquire','locked-value-read'];
        return array_combine($stages,array_map(fn($stage)=>[$stage],$stages));
    }
    #[DataProvider('failureStages')]
    public function test_redis_oom_at_cache_read_add_or_lock_runs_producer_once(string $stage): void
    {
        Log::spy();$cache=$this->repository();$oom=$this->oom();$calls=0;
        if ($stage==='generation-read') $cache->shouldReceive('get')->with($this->generationKey())->once()->andThrow($oom);
        elseif (in_array($stage,['generation-add','generation-reread'],true)) {
            $cache->shouldReceive('get')->with($this->generationKey())->once()->ordered()->andReturnNull();
            $add=$cache->shouldReceive('add')->with($this->generationKey(),Mockery::type('string'),Mockery::type(\DateTimeInterface::class))->once()->ordered();
            if ($stage==='generation-add') $add->andThrow($oom);
            else {
                $add->andReturnTrue();
                $cache->shouldReceive('get')->with($this->generationKey())->once()->ordered()->andThrow($oom);
            }
        } else {
            $cache->shouldReceive('get')->with($this->generationKey())->once()->andReturn('generation-1');
            if ($stage==='value-read') $cache->shouldReceive('get')->with($this->valueKey())->once()->andThrow($oom);
            else {
                $cache->shouldReceive('get')->with($this->valueKey())->once()->ordered()->andReturnNull();
                if ($stage==='lock-create') $cache->shouldReceive('lock')->with($this->valueKey().':build',30)->once()->andThrow($oom);
                else {
                    $lock=Mockery::mock();$cache->shouldReceive('lock')->with($this->valueKey().':build',30)->once()->andReturn($lock);
                    $block=$lock->shouldReceive('block')->with(5,Mockery::type('Closure'))->once();
                    if ($stage==='lock-acquire') $block->andThrow($oom);
                    else {
                        $cache->shouldReceive('get')->with($this->valueKey())->once()->ordered()->andThrow($oom);
                        $block->andReturnUsing(fn($seconds,$load)=>$load());
                    }
                }
            }
        }
        $cache->shouldReceive('put')->never();
        $this->assertSame(['source'=>'database'],ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls) {$calls++;return ['source'=>'database'];}));
        $this->assertSame(1,$calls);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn($message,$context)=>!str_contains(json_encode([$message,$context]),'credential-sentinel'));
    }
    public function test_redis_oom_writing_computed_value_does_not_rerun_producer(): void
    {
        $cache=$this->repository();$this->miss($cache);$this->lock($cache);$calls=0;
        $cache->shouldReceive('put')->with($this->valueKey(),['generation'=>'generation-1','value'=>null],60)->once()->andThrow($this->oom());
        $this->assertNull(ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls) {$calls++;return null;}));
        $this->assertSame(1,$calls);
    }
    public function test_lock_release_oom_returns_already_computed_value_once(): void
    {
        $cache=$this->repository();$this->miss($cache);$calls=0;$oom=$this->oom();
        $cache->shouldReceive('put')->once()->andReturnTrue();
        $this->lock($cache,function ($seconds,$load) use ($oom) {$load();throw $oom;});
        $this->assertSame('database-result',ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls) {$calls++;return 'database-result';}));
        $this->assertSame(1,$calls);
    }
    public function test_lock_release_failure_does_not_mask_database_exception_or_repeat_producer(): void
    {
        $cache=$this->repository();$this->miss($cache);$calls=0;$oom=$this->oom();$databaseError=new \RuntimeException('database failure');
        $cache->shouldReceive('put')->never();
        $this->lock($cache,function ($seconds,$load) use ($oom) {try {$load();} finally {throw $oom;}});
        try {ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls,$databaseError) {$calls++;throw $databaseError;});$this->fail('Database exception must propagate.');}
        catch (\Throwable $e) {$this->assertSame($databaseError,$e);}
        $this->assertSame(1,$calls);
    }
    public function test_lock_release_oom_after_concurrent_cache_fill_preserves_cached_response(): void
    {
        $cache=$this->repository();$calls=0;$oom=$this->oom();
        $cache->shouldReceive('get')->with($this->generationKey())->once()->andReturn('generation-1');
        $cache->shouldReceive('get')->with($this->valueKey())->once()->ordered()->andReturnNull();
        $cache->shouldReceive('get')->with($this->valueKey())->once()->ordered()->andReturn(['generation'=>'generation-1','value'=>'concurrent-result']);
        $cache->shouldReceive('put')->never();
        $this->lock($cache,function ($seconds,$load) use ($oom) {$load();throw $oom;});
        $this->assertSame('concurrent-result',ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls) {$calls++;return 'wrong';}));
        $this->assertSame(0,$calls);
    }
    public function test_producer_redis_or_lock_timeout_exception_is_not_an_optional_cache_failure(): void
    {
        foreach ([$this->oom(),new LockTimeoutException()] as $error) {
            $cache=$this->repository();$this->miss($cache);$this->lock($cache);$calls=0;
            $cache->shouldReceive('put')->never();
            try {ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls,$error) {$calls++;throw $error;});$this->fail('Producer exception must propagate.');}
            catch (\Throwable $e) {$this->assertSame($error,$e);}
            $this->assertSame(1,$calls);
        }
    }
    public function test_lock_release_oom_does_not_hide_cache_programming_error(): void
    {
        $cache=$this->repository();$this->miss($cache);$calls=0;$oom=$this->oom();$error=new \LogicException('cache serialization bug');
        $cache->shouldReceive('put')->once()->andThrow($error);
        $this->lock($cache,function ($seconds,$load) use ($oom) {try {$load();} finally {throw $oom;}});
        try {ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls) {$calls++;return 'database';});$this->fail('Programming exception must propagate.');}
        catch (\Throwable $e) {$this->assertSame($error,$e);}
        $this->assertSame(1,$calls);
    }
    public function test_non_backend_cache_errors_are_not_hidden(): void
    {
        foreach ([new \LogicException('cache programming error'),new ServerException('WRONGTYPE Operation against a key holding the wrong kind of value')] as $error) {
            $cache=$this->repository();$calls=0;
            $cache->shouldReceive('get')->with($this->generationKey())->once()->andThrow($error);
            try {ApiCache::remember(self::GROUP,self::KEY,60,function () use (&$calls) {$calls++;return 'wrong';});$this->fail('Programming exception must propagate.');}
            catch (\Throwable $e) {$this->assertSame($error,$e);}
            $this->assertSame(0,$calls);
        }
    }
    public function test_connection_failure_falls_back_and_warning_is_capped_per_request(): void
    {
        Log::spy();$cache=$this->repository();$calls=0;
        $error=new ConnectionException(Mockery::mock(NodeConnectionInterface::class),'Connection refused redis://credential-sentinel@host');
        $cache->shouldReceive('get')->with($this->generationKey())->times(3)->andThrow($error);
        $producer=function () use (&$calls) {$calls++;return 'database';};
        $this->assertSame('database',ApiCache::remember(self::GROUP,self::KEY,60,$producer));
        $this->assertSame('database',ApiCache::remember(self::GROUP,self::KEY,60,$producer));
        Log::shouldHaveReceived('warning')->once();
        $this->app->instance('request',Request::create('/new-request'));
        $this->assertSame('database',ApiCache::remember(self::GROUP,self::KEY,60,$producer));
        $this->assertSame(3,$calls);
        Log::shouldHaveReceived('warning')->twice()->withArgs(fn($message,$context)=>!str_contains(json_encode([$message,$context]),'credential-sentinel'));
    }
    public function test_post_commit_redis_oom_does_not_report_failed_commit_and_forgets_old_generation(): void
    {
        // Guard against accidentally running a write test against an operator database.
        $this->assertSame('sqlite',DB::connection()->getDriverName());
        $this->assertSame(':memory:',config('database.connections.sqlite.database'));
        Schema::create('api_cache_commit_probe',fn($table)=>$table->integer('value'));
        $this->assertSame('old',ApiCache::remember(self::GROUP,self::KEY,60,fn()=>'old'));
        $manager=Cache::getFacadeRoot();$cache=Mockery::mock($manager);Cache::swap($cache);
        $cache->shouldReceive('forever')->with($this->generationKey(),Mockery::type('string'))->once()->andThrow($this->oom());
        $cache->shouldReceive('forget')->with($this->generationKey())->once()->andReturnUsing(fn($key)=>$manager->forget($key));
        DB::beginTransaction();DB::table('api_cache_commit_probe')->insert(['value'=>17]);ApiCache::clearGroup(self::GROUP);
        $cache->shouldNotHaveReceived('forever');
        DB::commit();
        $this->assertSame(17,(int)DB::table('api_cache_commit_probe')->value('value'));
        Cache::swap($manager);
        $this->assertNull(Cache::get($this->generationKey()));
        $this->assertSame('fresh',ApiCache::remember(self::GROUP,self::KEY,60,fn()=>'fresh'));
    }
    public function test_invalidation_connection_error_including_forget_does_not_throw(): void
    {
        $cache=$this->repository();$error=new ConnectionException(Mockery::mock(NodeConnectionInterface::class),'Connection refused');
        $cache->shouldReceive('forever')->with($this->generationKey(),Mockery::type('string'))->once()->andThrow($error);
        $cache->shouldReceive('forget')->with($this->generationKey())->once()->andThrow($error);
        ApiCache::clearGroup(self::GROUP);$this->addToAssertionCount(1);
    }
}
