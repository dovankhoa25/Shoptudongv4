<?php
namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ApiCache
{
    public static function remember(string $group, string $key, int $ttlSeconds, callable $producer): mixed
    {
        // Only the optional cache may fail open. Producer/database failures remain fatal.
        $hasResult=false;$result=null;$producerFailure=null;$loadFailure=null;
        $produce=function () use ($producer,&$hasResult,&$result,&$producerFailure) {
            try {$result=$producer();$hasResult=true;return $result;}
            catch (\Throwable $e) {$producerFailure=$e;throw $e;}
        };
        // Replace one generation on invalidation; concurrent writers cannot lose keys.
        $generationKey=self::key('generation',$group);
        $available=true;
        $generation=self::cacheOperation(fn()=>Cache::get($generationKey),'generation-read',$available);
        if (!$available) return $produce();
        if ($generation===null) {
            self::cacheOperation(fn()=>Cache::add($generationKey,(string)Str::uuid(),now()->addYears(10)),'generation-create',$available);
            if (!$available) return $produce();
            $generation=self::cacheOperation(fn()=>Cache::get($generationKey),'generation-read',$available);
            if (!$available) return $produce();
        }
        // Reuse the physical key, including on file cache, instead of leaving a file per invalidation.
        $resolved=self::key('value',$group,hash('sha256',$key));
        $cached=self::cacheOperation(fn()=>Cache::get($resolved),'value-read',$available);
        if (!$available) return $produce();
        if ($cached!==null && ($cached['generation'] ?? null)===$generation) return $cached['value'];
        $load=function () use ($resolved,$generation,$produce,$ttlSeconds,&$hasResult,&$result,&$loadFailure) {
            try {
                $available=true;
                $cached=self::cacheOperation(fn()=>Cache::get($resolved),'value-read',$available);
                if (!$available) return $produce();
                if ($cached!==null && ($cached['generation'] ?? null)===$generation) {
                    $result=$cached['value'];$hasResult=true;return $result;
                }
                $value=$produce();
                self::cacheOperation(fn()=>Cache::put($resolved,['generation'=>$generation,'value'=>$value],$ttlSeconds),'value-write',$available);
                return $value;
            } catch (\Throwable $e) {$loadFailure=$e;throw $e;}
        };
        try { return Cache::lock($resolved.':build',30)->block(5,$load); }
        catch (\Throwable $e) {
            // A Redis failure releasing a lock must not hide an exception from the producer.
            if ($producerFailure!==null) throw $producerFailure;
            if ($loadFailure!==null) throw $loadFailure;
            if ($e instanceof LockTimeoutException) return $hasResult?$result:$produce();
            if (!self::redisUnavailable($e)) throw $e;
            self::warnUnavailable('build-lock',$e);
            return $hasResult?$result:$produce();
        }
    }
    public static function clearGroup(string $group): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => self::invalidateGroup($group));
            return;
        }
        self::invalidateGroup($group);
    }
    private static function invalidateGroup(string $group): void
    {
        $key=self::key('generation',$group);$available=true;
        $written=self::cacheOperation(fn()=>Cache::forever($key,(string)Str::uuid()),'invalidate-generation',$available);
        // DEL is allowed during Redis maxmemory OOM and invalidates the old generation.
        // A connection outage may also stop DEL; public values still have their original short TTL.
        if (!$available || $written===false) self::cacheOperation(fn()=>Cache::forget($key),'invalidate-forget',$available);
    }
    private static function cacheOperation(callable $operation,string $phase,bool &$available): mixed
    {
        try {return $operation();}
        catch (\Throwable $e) {
            if (!self::redisUnavailable($e)) throw $e;
            $available=false;self::warnUnavailable($phase,$e);return null;
        }
    }
    private static function redisUnavailable(\Throwable $e): bool
    {
        do {
            if ($e instanceof \Predis\Connection\ConnectionException) return true;
            if (($e instanceof \RedisException || $e instanceof \Predis\Response\ServerException)
                && preg_match('/^OOM(?:\s|$)/i',ltrim($e->getMessage()))) return true;
            if ($e instanceof \RedisException && preg_match('/connection (?:refused|closed|lost|timed out|reset)|read error on connection|went away|socket error|php_network_getaddresses|getaddrinfo|not connected/i',$e->getMessage())) return true;
        } while (($e=$e->getPrevious())!==null);
        return false;
    }
    private static function warnUnavailable(string $phase,\Throwable $e): void
    {
        static $processWarned=false;
        try {
            $request=app()->bound('request')?app('request'):null;
            if ($request instanceof \Illuminate\Http\Request) {
                if ($request->attributes->get('optional_api_cache_unavailable_warned')) return;
                $request->attributes->set('optional_api_cache_unavailable_warned',true);
            } else {
                if ($processWarned) return;
                $processWarned=true;
            }
            // Never log Redis URLs, cache keys or exception messages that may contain credentials.
            Log::warning('Optional API cache unavailable; database response remains authoritative.',[
                'operation'=>$phase,'exception_type'=>get_class($e),
            ]);
        } catch (\Throwable $ignored) { /* Logging cannot affect an already committed result. */ }
    }
    /** Resolve resources before caching, including pagination/meta and nested resources. */
    public static function rememberJson(string $group, string $key, int $ttlSeconds, callable $producer): mixed
    {
        return self::remember($group, self::key('json-v1', $key), $ttlSeconds, function () use ($producer) {
            $value = $producer();
            if ($value instanceof \Illuminate\Http\Resources\Json\JsonResource) {
                return $value->response()->getData(true);
            }
            if ($value instanceof \Illuminate\Http\JsonResponse) return $value->getData(true);

            return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        });
    }
    public static function clearGroups(array $groups): void
    {
        foreach (array_unique($groups) as $group) self::clearGroup((string)$group);
    }
    public static function key(string ...$parts): string
    {
        return 'api:v1:'.implode('|',array_map(fn($part): string=>rawurlencode((string)$part),$parts));
    }
}
