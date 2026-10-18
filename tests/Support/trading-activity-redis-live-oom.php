<?php
// Explicit isolated Redis fixture. No production connection, dispatch, wallet, or database mutation.
if (getenv('NRO_ACTIVITY_REDIS_TEST_ALLOWED') !== 'true') throw new RuntimeException('Isolated Redis test authorization required.');
$port = (int) getenv('NRO_ACTIVITY_REDIS_TEST_PORT');
$pid = (int) getenv('NRO_ACTIVITY_REDIS_TEST_PID');
$root = realpath(getenv('NRO_ACTIVITY_REDIS_TEST_DIRECTORY') ?: '');
$base = realpath(__DIR__.'/runtime-reports');
if ($port < 49152 || $port > 65535 || $pid <= 0 || !$root || !$base || !str_starts_with(strtolower($root), strtolower($base.DIRECTORY_SEPARATOR))
    || !str_starts_with(basename($root), 'activity-redis-live-')) throw new RuntimeException('Owned local Redis fixture required.');
foreach (['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)),
    'APP_CONFIG_CACHE'=>$root.'/unused-config.php','APP_ROUTES_CACHE'=>$root.'/unused-routes.php',
    'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','DB_URL'=>'null',
    'BROADCAST_CONNECTION'=>'log','LOG_CHANNEL'=>'stderr','QUEUE_CONNECTION'=>'sync'] as $key=>$value) putenv($key.'='.$value);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$prefix = 'isolated_activity_'.bin2hex(random_bytes(8)).':';
config(['database.redis'=>['client'=>'predis','options'=>['prefix'=>$prefix],
    'default'=>['host'=>'127.0.0.1','port'=>$port,'database'=>7,'password'=>null,'username'=>null,'read_write_timeout'=>3],
    'cache'=>['host'=>'127.0.0.1','port'=>$port,'database'=>9,'password'=>null,'username'=>null,'read_write_timeout'=>3]],
    'queue.connections.redis.driver'=>'redis','queue.connections.redis.connection'=>'default',
    'trading_v3.activity_queue_connection'=>'redis','trading_v3.activity_queue'=>'trading-activity-live',
    'trading_v3.activity_dispatch'=>'after_response','cache.default'=>'array','session.driver'=>'array',
    'cache.stores.redis'=>['driver'=>'redis','connection'=>'cache','prefix'=>'cache_live:']]);
$app->forgetInstance('redis'); Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');
$redis = Illuminate\Support\Facades\Redis::connection('default');
$info = $redis->info('server'); $server = $info['Server'] ?? $info;
if ((int)($server['process_id'] ?? 0) !== $pid) throw new RuntimeException('Redis PID does not match the owned test instance.');
$other = new Predis\Client(['host'=>'127.0.0.1','port'=>$port,'database'=>8,'read_write_timeout'=>3]);
$queue = 'queues:trading-activity-live';
$keys = [$queue,$queue.':reserved',$queue.':delayed',$queue.':notify'];
$foreign = ['queues:financial-receipts','cache:session-keep'];
$report = ['isolated'=>true,'redis_pid'=>$pid,'port'=>$port,'database'=>7,'other_database'=>8,'prefix_verified'=>false,'cases'=>[],'success'=>false];
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function digest($redis,array $keys): array { $result=[];foreach($keys as $key){$value=$redis->dump($key);$result[$key]=$value===null?null:hash('sha256',$value);}return $result; }
function command(array $options=[]): array { $code=Illuminate\Support\Facades\Artisan::call('trading:activity-redis',$options);return ['code'=>$code,'output'=>trim(Illuminate\Support\Facades\Artisan::output())]; }
function oomProof($redis): bool { try{$redis->set('must-reject-oom','x');return false;}catch(Predis\Response\ServerException $e){return str_contains($e->getMessage(),'OOM');} }
try {
    $verifiedPrimitive=json_encode(['data'=>['commandName'=>App\Jobs\PublishTradingBotActivities::class]]);$foreignPrimitive='unknown-financial-payload';
    $redis->rpush('primitive:list',$verifiedPrimitive,$foreignPrimitive);$redis->zadd('primitive:zset',1,$verifiedPrimitive,2,$foreignPrimitive);
    $redis->config('SET','maxmemory','1');check(oomProof($redis),'Primitive test did not reach OOM.');
    check((int)$redis->lrem('primitive:list',0,$verifiedPrimitive)===1,'LREM exact payload failed under OOM.');
    check((int)$redis->zrem('primitive:zset',$verifiedPrimitive)===1,'ZREM exact payload failed under OOM.');
    check($redis->lrange('primitive:list',0,-1)===[$foreignPrimitive]&&$redis->zrange('primitive:zset',0,-1)===[$foreignPrimitive],'Byte-exact primitive removed a foreign payload.');
    check((int)$redis->del('primitive:list','primitive:zset')===2,'Direct DEL outside MULTI failed under OOM.');
    $report['cases'][]=['case'=>'real_oom_lrem_zrem_del_outside_multi','result'=>'PASS','byte_exact_removal'=>true,'unknown_payload_preserved'=>true];
    $redis->config('SET','maxmemory','0');
    $cacheRedis=Illuminate\Support\Facades\Cache::store('redis')->getStore()->connection();
    $cacheTargets=['cache_live:trading-v3:activity:bot:3','cache_live:trading-v3:activity:gem_bot:9983','cache_live:trading-v3:gem-timeout-policy'];
    $cacheForeign=['cache_live:trading-v3:nonce:live','cache_live:session:keep','cache_live:trading-v3:activity:order:17'];
    foreach([...$cacheTargets,...$cacheForeign]as$key)$cacheRedis->setex($key,190,'isolated-cache-sentinel');
    $cacheBefore=digest($cacheRedis,$cacheForeign);
    $redis->rpush($foreign[0],json_encode(['data'=>['commandName'=>'FinancialReceiptJob']]));$redis->set($foreign[1],'keep-local-session');
    $other->set($prefix.$queue,'other-database-sentinel');
    $payload=fn()=>json_encode(['uuid'=>bin2hex(random_bytes(16)),'displayName'=>App\Jobs\PublishTradingBotActivities::class,
        'job'=>'Illuminate\\Queue\\CallQueuedHandler@call','data'=>['commandName'=>App\Jobs\PublishTradingBotActivities::class,'command'=>'test-only-not-unserialized']]);
    $redis->rpush($keys[0],$payload());$redis->zadd($keys[1],time()+30,$payload());$redis->zadd($keys[2],time()+60,$payload());$redis->rpush($keys[3],'1');
    $raw=$redis->client();
    check((int)$raw->executeRaw(['EXISTS',$prefix.$queue])===1&&(int)$raw->executeRaw(['EXISTS',$queue])===0,'Configured prefix was not applied to the actual Redis keys.');
    $report['prefix_verified']=true;$before=digest($redis,[...$keys,...$foreign]);
    $read=command();check($read['code']===0&&digest($redis,[...$keys,...$foreign])===$before,'Default diagnostic changed keys.');
    $report['cases'][]=['case'=>'default_read_only','result'=>'PASS','target_keys'=>4,'target_jobs'=>3,'unchanged'=>true,'command'=>$read];
    $redis->config('SET','maxmemory','1');check(oomProof($redis),'SET was not rejected by real Redis OOM.');
    $readOom=command();check($readOom['code']===0&&digest($redis,[...$keys,...$foreign])===$before,'Default OOM diagnostic changed keys.');
    $clear=command(['--clear-backlog'=>true]);$report['attempted_oom_diagnostic']=$readOom;$report['attempted_oom_cleanup']=$clear;
    if($clear['code']!==0){
        Illuminate\Support\Facades\Redis::purge('default');$redis=Illuminate\Support\Facades\Redis::connection('default');
        $report['remaining_queue_keys']=array_map(fn($key)=>(int)$redis->exists($key),$keys);$report['protocol_probe']=[];
        foreach(['watch','multi','del','exec'] as $operation){try{
            $result=in_array($operation,['watch','del'],true)?$redis->{$operation}(...$keys):$redis->{$operation}();
            $report['protocol_probe'][]=['operation'=>$operation,'ok'=>true,'result_type'=>get_debug_type($result)];
        }catch(Throwable $error){$report['protocol_probe'][]=['operation'=>$operation,'ok'=>false,'class'=>get_class($error),'error'=>str_contains($error->getMessage(),'OOM')?'OOM':$error->getMessage()];break;}}
        try{$redis->discard();}catch(Throwable $ignored){}try{$redis->unwatch();}catch(Throwable $ignored){}
    }
    check($clear['code']===0,'Cleanup command failed against real Redis under OOM.');
    foreach($keys as $key)check((int)$redis->exists($key)===0,'A verified target queue key remains.');
    check(digest($redis,$foreign)===array_intersect_key($before,array_flip($foreign)),'Foreign queue or cache sentinel changed.');
    check($other->get($prefix.$queue)==='other-database-sentinel','Another Redis database changed.');
    $report['cases'][]=['case'=>'real_oom_exact_payload_cleanup','result'=>'PASS','oom_set_rejected'=>true,'removed_keys'=>4,
        'foreign_queue_and_cache_preserved'=>true,'other_database_preserved'=>true,'diagnostic'=>$readOom,'command'=>$clear];
    // Re-seed only this owned instance, then verify an unknown financial payload blocks all deletion under OOM.
    $redis->config('SET','maxmemory','0');$redis->rpush($keys[0],json_encode(['data'=>['commandName'=>'FinancialReceiptJob']]));$redis->rpush($keys[3],'1');
    $unknownBefore=digest($redis,[...$keys,...$foreign]);$redis->config('SET','maxmemory','1');check(oomProof($redis),'The second real OOM condition was not established.');
    $refuse=command(['--clear-backlog'=>true]);check($refuse['code']===1&&digest($redis,[...$keys,...$foreign])===$unknownBefore,'Unknown job refusal deleted or changed keys.');
    check($other->get($prefix.$queue)==='other-database-sentinel','Unknown job refusal changed another database.');
    $report['cases'][]=['case'=>'unknown_job_refuses_all_cleanup_under_oom','result'=>'PASS','all_keys_unchanged'=>true,'command'=>$refuse];
    $toolCache=command(['--clear-tool-cache'=>true]);check($toolCache['code']===0,'Exact legacy tool cache cleanup failed under OOM.');
    foreach($cacheTargets as$key)check((int)$cacheRedis->exists($key)===0,'A targeted legacy tool cache key remains.');
    check(digest($cacheRedis,$cacheForeign)===$cacheBefore,'Auth nonce, session, or unknown activity-type cache was changed.');
    check(digest($redis,[...$keys,...$foreign])===$unknownBefore,'Cache-only cleanup changed queue data.');
    check($other->get($prefix.$queue)==='other-database-sentinel','Cache cleanup changed another Redis database.');
    $report['cases'][]=['case'=>'real_oom_exact_legacy_cache_cleanup','result'=>'PASS','cache_database'=>9,'removed_exact_keys'=>3,
        'hmac_nonce_and_session_preserved'=>true,'unknown_activity_type_preserved'=>true,'queue_preserved'=>true,'command'=>$toolCache];
    $report['success']=true;
} catch (Throwable $e) { $report['failure']=['class'=>get_class($e),'message'=>$e instanceof RuntimeException?$e->getMessage():'Isolated Redis operation failed; connection details omitted.']; }
finally { try{$redis->config('SET','maxmemory','0');}catch(Throwable $ignored){}file_put_contents($root.'/results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
echo $report['success']?"PASS: real Redis OOM exact job cleanup and legacy tool cache cleanup; foreign queue/cache/database/auth nonce preserved; default read-only and unknown-job refusal.\n":"FAIL: isolated real Redis verification; see sanitized results.json.\n";
exit($report['success']?0:1);
