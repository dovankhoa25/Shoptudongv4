<?php
namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ApiCache
{
    public static function remember(string $group, string $key, int $ttlSeconds, callable $producer): mixed
    {
        // Replace one generation on invalidation; concurrent writers cannot lose keys.
        $generationKey=self::key('generation',$group);
        $generation=Cache::get($generationKey);
        if ($generation===null) {
            Cache::add($generationKey,(string)Str::uuid(),now()->addYears(10));
            $generation=Cache::get($generationKey);
        }
        // Reuse the physical key, including on file cache, instead of leaving a file per invalidation.
        $resolved=self::key('value',$group,hash('sha256',$key));
        if (($cached=Cache::get($resolved))!==null && ($cached['generation'] ?? null)===$generation) return $cached['value'];
        $load=function () use ($resolved,$generation,$producer,$ttlSeconds) {
            if (($cached=Cache::get($resolved))!==null && ($cached['generation'] ?? null)===$generation) return $cached['value'];
            $value=$producer();Cache::put($resolved,['generation'=>$generation,'value'=>$value],$ttlSeconds);return $value;
        };
        try { return Cache::lock($resolved.':build',30)->block(5,$load); }
        catch (LockTimeoutException $e) { return $producer(); }
    }
    public static function clearGroup(string $group): void
    {
        Cache::forever(self::key('generation',$group),(string)Str::uuid());
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
