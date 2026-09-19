<?php

namespace Tests\Feature;

use App\Models\AccessIpBlock;
use App\Services\AccessIpBlockCache;
use App\Support\ApiCache;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessIpBlockCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        // No outer test transaction: exercise real commit/rollback cache boundaries.
        Schema::create('access_ip_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('network', 49);
            $table->string('reason', 500);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        config(['access_security.ip_block_cache_store' => null, 'access_security.ip_block_cache_ttl' => 60]);
        // Optional isolated Redis validation. Never reuse the application's Redis connection.
        if ($port = getenv('ACCESS_IP_BLOCK_TEST_REDIS_PORT')) {
            if (! class_exists(\Predis\Client::class) && ($autoload = getenv('ACCESS_IP_BLOCK_TEST_AUTOLOAD'))) {
                $loader = require $autoload;
                // Keep Laravel's inferred application root tied to the project Composer loader.
                $loader->unregister();
                spl_autoload_register(static function (string $class) use ($loader): void {
                    if (str_starts_with($class, 'Predis\\')) {
                        $loader->loadClass($class);
                    }
                });
            }
            config(['database.redis.client' => 'predis', 'database.redis.options.prefix' => '',
                'database.redis.ip_block_test' => ['host' => '127.0.0.1', 'port' => (int) $port,
                    'database' => 0, 'password' => null, 'timeout' => 1, 'read_write_timeout' => 1],
                'cache.stores.ip_block_test' => ['driver' => 'redis', 'connection' => 'ip_block_test',
                    'lock_connection' => 'ip_block_test', 'prefix' => 'codex-test:'.Str::uuid().':'],
                'cache.default' => 'ip_block_test']);
        }
    }

    private function block(array $attributes = []): AccessIpBlock
    {
        return AccessIpBlock::create([...['network' => '198.51.100.0/24', 'reason' => 'test'], ...$attributes]);
    }

    private function prefix(): string
    {
        return app(AccessIpBlockCache::class)->keyPrefix();
    }

    public function test_warm_cache_removes_repeated_database_reads_and_only_stores_rule_fields(): void
    {
        $this->block();
        $this->block(['network' => '2001:db8:abcd::/48']);
        DB::enableQueryLog();
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $this->assertCount(1, DB::getQueryLog());
        DB::flushQueryLog();
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
            $this->assertTrue(AccessIpBlock::blocks('2001:db8:abcd:12::1'));
            $this->assertFalse(AccessIpBlock::blocks('203.0.113.1'));
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $rules = Cache::get($this->prefix().':rules')['rules'];
        $this->assertSame(['network', 'expires_at'], array_keys($rules[0]));
    }

    public function test_empty_list_is_cached_and_null_ip_does_not_query(): void
    {
        DB::enableQueryLog();
        $this->assertFalse(AccessIpBlock::blocks(null));
        $this->assertSame([], DB::getQueryLog());
        $this->assertFalse(AccessIpBlock::blocks('203.0.113.1'));
        $this->assertFalse(AccessIpBlock::blocks('203.0.113.2'));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_create_unblock_reblock_edit_and_delete_invalidate_a_warm_list(): void
    {
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $rule = $this->block();
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $rule->update(['revoked_at' => now()]);
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $rule->update(['revoked_at' => null]);
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $rule->update(['network' => '203.0.113.0/24']);
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $this->assertTrue(AccessIpBlock::blocks('203.0.113.22'));
        $rule->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse(AccessIpBlock::blocks('203.0.113.22'));
        $rule->update(['expires_at' => null]);
        $this->assertTrue(AccessIpBlock::blocks('203.0.113.22'));
        $rule->delete();
        $this->assertFalse(AccessIpBlock::blocks('203.0.113.22'));
    }

    public function test_deadline_is_enforced_while_snapshot_is_still_warm(): void
    {
        $this->freezeTime();
        $this->block(['expires_at' => now()->addSeconds(10)]);
        $this->block(['network' => '203.0.113.0/24']);
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $this->travel(10)->seconds();
        DB::enableQueryLog();
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $this->assertTrue(AccessIpBlock::blocks('203.0.113.22'));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNotNull(Cache::get($this->prefix().':rules'));
    }

    public function test_transaction_reads_do_not_publish_uncommitted_rules_and_rollback_keeps_old_cache(): void
    {
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $generation = Cache::get($this->prefix().':generation');
        DB::beginTransaction();
        $this->block();
        $this->assertSame($generation, Cache::get($this->prefix().':generation'));
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $this->assertSame([], Cache::get($this->prefix().':rules')['rules']);
        DB::rollBack();
        $this->assertSame($generation, Cache::get($this->prefix().':generation'));
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
    }

    public function test_invalidation_waits_for_outermost_commit(): void
    {
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $generation = Cache::get($this->prefix().':generation');
        DB::beginTransaction();
        DB::beginTransaction();
        $this->block();
        DB::commit();
        $this->assertSame($generation, Cache::get($this->prefix().':generation'));
        DB::commit();
        $this->assertNotSame($generation, Cache::get($this->prefix().':generation'));
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
    }

    public function test_writer_during_a_cold_read_cannot_publish_the_old_snapshot(): void
    {
        $written = false;
        DB::listen(function ($query) use (&$written): void {
            if (! $written && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'access_ip_blocks')) {
                $written = true;
                $this->block();
            }
        });
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $this->assertTrue($written);
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
    }

    public function test_late_old_cache_writer_and_evicted_generation_are_rejected(): void
    {
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $old = Cache::get($this->prefix().':rules');
        $this->block();
        Cache::put($this->prefix().':rules', $old, 60);
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        Cache::forget($this->prefix().':generation');
        DB::table('access_ip_blocks')->delete();
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
    }

    public function test_busy_builder_reads_database_without_waiting_or_overwriting_shared_cache(): void
    {
        $this->block();
        $lock = Cache::lock($this->prefix().':build', 10);
        $this->assertTrue($lock->get());
        try {
            DB::enableQueryLog();
            $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
            $this->assertCount(1, DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertNull(Cache::get($this->prefix().':rules'));
        } finally {
            $lock->release();
        }
    }

    public function test_cache_outage_still_enforces_database_rules(): void
    {
        $this->block();
        Cache::partialMock()->shouldReceive('store')->andThrow(new \RuntimeException('cache unavailable'));
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $this->assertFalse(AccessIpBlock::blocks('203.0.113.22'));
    }

    public function test_database_failure_does_not_become_an_empty_allow_list(): void
    {
        Schema::drop('access_ip_blocks');
        $this->expectException(QueryException::class);
        AccessIpBlock::blocks('198.51.100.22');
    }

    public function test_failed_invalidation_reports_unsynchronized_commit_and_can_be_recovered(): void
    {
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $manager = app('cache');
        Cache::partialMock()->shouldReceive('store')->andThrow(new \RuntimeException('cache unavailable'));
        try {
            $this->block();
            $this->fail('A failed cache invalidation must be reported.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        } finally {
            Cache::swap($manager);
        }
        $this->assertDatabaseCount('access_ip_blocks', 1);
        $this->artisan('security:ip-block-cache:clear')->assertSuccessful();
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
    }

    public function test_invalidation_keeps_other_cache_namespaces_untouched(): void
    {
        foreach (['public:nick', 'public:catalog', 'internal:settings', 'admin:analytics'] as $group) {
            ApiCache::remember($group, 'marker', 60, fn () => 'keep');
        }
        $keys = ['admin-live:state:example', 'traffic:v1:0:0', 'frontend-clients:allowed-origins:v1', 'frontend-cache:ack:example'];
        foreach ($keys as $key) {
            Cache::put($key, 'keep', 60);
        }
        $this->block();
        $this->artisan('security:ip-block-cache:clear')->assertSuccessful();
        foreach ($keys as $key) {
            $this->assertSame('keep', Cache::get($key));
        }
        foreach (['public:nick', 'public:catalog', 'internal:settings', 'admin:analytics'] as $group) {
            $this->assertSame('keep', ApiCache::remember($group, 'marker', 60, fn () => 'unexpected miss'));
        }
    }

    public function test_override_store_and_database_scope_do_not_collide_with_default_cache(): void
    {
        config(['cache.stores.ip_only' => ['driver' => 'array'], 'access_security.ip_block_cache_store' => 'ip_only']);
        $this->block();
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
        $this->assertNotNull(Cache::store('ip_only')->get($this->prefix().':rules'));
        $this->assertNull(Cache::get($this->prefix().':rules'));
        $other = new SQLiteConnection(new \PDO('sqlite::memory:'), 'another_database', '', ['driver' => 'sqlite']);
        $this->assertNotSame($this->prefix(), app(AccessIpBlockCache::class)->keyPrefix($other));
    }

    public function test_manual_sql_clear_command_only_invalidates_security_cache(): void
    {
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        DB::table('access_ip_blocks')->insert(['network' => '198.51.100.0/24', 'reason' => 'manual SQL']);
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        $this->artisan('security:ip-block-cache:clear')->assertSuccessful();
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
    }

    public function test_expired_snapshot_is_rebuilt_and_redis_uses_the_configured_ttl(): void
    {
        $this->assertFalse(AccessIpBlock::blocks('198.51.100.22'));
        DB::table('access_ip_blocks')->insert(['network' => '198.51.100.0/24', 'reason' => 'manual SQL']);
        $key = $this->prefix().':rules';
        if (getenv('ACCESS_IP_BLOCK_TEST_REDIS_PORT')) {
            $store = Cache::getStore();
            $physicalKey = $store->getPrefix().$key;
            $ttl = $store->connection()->ttl($physicalKey);
            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(60, $ttl);
            $store->connection()->expire($physicalKey, 0);
        } else {
            $this->travel(61)->seconds();
        }
        $this->assertNull(Cache::get($key));
        $this->assertTrue(AccessIpBlock::blocks('198.51.100.22'));
    }
}
