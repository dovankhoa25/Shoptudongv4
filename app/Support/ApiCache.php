<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class ApiCache
{
    private const GROUP_INDEX_TTL_SECONDS = 3600;

    public static function remember(string $group, string $key, int $ttlSeconds, callable $producer): mixed
    {
        if (Cache::has($key)) {
            self::recordGroupKey($group, $key, $ttlSeconds);

            return Cache::get($key);
        }

        $value = $producer();
        Cache::put($key, $value, $ttlSeconds);
        self::recordGroupKey($group, $key, $ttlSeconds);

        return $value;
    }

    public static function clearGroup(string $group): void
    {
        $indexKey = self::groupIndexKey($group);
        $keys = (array) Cache::get($indexKey, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Cache::forget($indexKey);
    }

    public static function clearGroups(array $groups): void
    {
        foreach ($groups as $group) {
            self::clearGroup((string) $group);
        }
    }

    public static function key(string ...$parts): string
    {
        return 'api:v1:'.implode('|', array_map(fn ($part): string => rawurlencode((string) $part), $parts));
    }

    private static function groupIndexKey(string $group): string
    {
        return self::key('group', $group);
    }

    private static function recordGroupKey(string $group, string $key, int $ttlSeconds): void
    {
        $indexKey = self::groupIndexKey($group);
        $keys = (array) Cache::get($indexKey, []);

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put($indexKey, $keys, max(self::GROUP_INDEX_TTL_SECONDS, $ttlSeconds));
        }
    }
}
