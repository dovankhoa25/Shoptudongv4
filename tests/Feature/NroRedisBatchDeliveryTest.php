<?php
namespace Tests\Feature;
class NroRedisBatchDeliveryTest extends NroBatchDeliveryTest {
    protected function setUp(): void {
        parent::setUp();
        if(getenv('NRO_TEST_REDIS_PORT')!=='16389') $this->markTestSkipped('Run with isolated Redis on localhost:16389.');
        config(['cache.default'=>'redis','cache.prefix'=>'nro-batch-test-'.bin2hex(random_bytes(8)).':',
            'database.redis.client'=>extension_loaded('redis')?'phpredis':'predis','database.redis.options.prefix'=>'isolated:',
            'database.redis.cache'=>['host'=>'127.0.0.1','port'=>16389,'database'=>1,'password'=>null,'url'=>null],
            'database.redis.default'=>['host'=>'127.0.0.1','port'=>16389,'database'=>0,'password'=>null,'url'=>null]]);
        app('redis')->purge('cache');app('redis')->purge('default');app('cache')->forgetDriver('redis');
    }
}
