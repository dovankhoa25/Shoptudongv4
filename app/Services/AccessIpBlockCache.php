<?php

namespace App\Services;

use App\Models\AccessIpBlock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class AccessIpBlockCache
{
    public function blocks(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }
        $rules = $this->rules();
        $now = now()->getTimestamp();
        foreach ($rules as $rule) {
            // A warm cache must never extend a temporary ban past its deadline.
            if (($rule['expires_at'] === null || $rule['expires_at'] > $now)
                && IpUtils::checkIp($ip, $rule['network'])) {
                return true;
            }
        }

        return false;
    }

    public function keyPrefix(?Connection $connection = null): string
    {
        $connection ??= (new AccessIpBlock)->getConnection();
        $scope = hash('sha256', json_encode([
            $connection->getName(), $connection->getConfig('driver'),
            $connection->getConfig('host'), $connection->getConfig('port'), $connection->getDatabaseName(),
        ], JSON_THROW_ON_ERROR));

        // Dedicated namespace + DB scope, with a Redis Cluster hash tag for the paired MGET.
        // Cache/Redis connection prefixes are still applied by Laravel.
        return 'access-security:ip-blocks:v1:{'.substr($scope, 0, 32).'}';
    }

    public function invalidate(?Connection $connection = null): void
    {
        $connection ??= (new AccessIpBlock)->getConnection();
        $clear = function () use ($connection): void {
            try {
                $cache = Cache::store(config('access_security.ip_block_cache_store'));
                $prefix = $this->keyPrefix($connection);
                if (! $cache->forever($prefix.':generation', (string) Str::uuid())) {
                    throw new \RuntimeException('Cannot advance IP block cache generation.');
                }
                $cache->forget($prefix.':rules');
            } catch (Throwable $exception) {
                // Do not tell the operator a committed ban/unban is synchronized when it is not.
                throw new HttpException(503,
                    'Dữ liệu chặn IP đã được lưu nhưng cache chưa đồng bộ. Sau khi khôi phục cache, chạy php artisan security:ip-block-cache:clear rồi kiểm tra lại.',
                    $exception);
            }
        };
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($clear);
        } else {
            $clear();
        }
    }

    /** @return list<array{network: string, expires_at: ?int}> */
    private function rules(): array
    {
        $connection = (new AccessIpBlock)->getConnection();
        // Never publish a transaction's uncommitted or repeatable-read snapshot into shared cache.
        if ($connection->transactionLevel() > 0) {
            return $this->readDatabase($connection);
        }
        try {
            $cache = Cache::store(config('access_security.ip_block_cache_store'));
            $prefix = $this->keyPrefix($connection);
            $generationKey = $prefix.':generation';
            $rulesKey = $prefix.':rules';
            $snapshot = $cache->many([$generationKey, $rulesKey]);
            $generation = $snapshot[$generationKey] ?? null;
            $cached = $snapshot[$rulesKey] ?? null;
            if (is_string($generation) && is_array($cached)
                && ($cached['generation'] ?? null) === $generation && is_array($cached['rules'] ?? null)) {
                return $cached['rules'];
            }
            // Non-blocking lock: a busy cache builder must not hold up HTTP requests for seconds.
            $lock = $cache->lock($prefix.':build', 10);
            if (! $lock->get()) {
                return $this->readDatabase($connection);
            }
            try {
                $generation = $cache->get($generationKey);
                if (! is_string($generation)) {
                    $cache->add($generationKey, (string) Str::uuid(), 86400 * 365);
                    $generation = $cache->get($generationKey);
                }
                $rules = $this->readDatabase($connection);
                if (is_string($generation) && $cache->get($generationKey) === $generation) {
                    $cache->put($rulesKey, ['generation' => $generation, 'rules' => $rules],
                        max(1, (int) config('access_security.ip_block_cache_ttl', 60)));
                    if ($cache->get($generationKey) === $generation) {
                        return $rules;
                    }
                }

                // A writer committed during this read. Do not return the earlier snapshot.
                return $this->readDatabase($connection);
            } finally {
                $lock->release();
            }
        } catch (QueryException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Redis being unavailable must never be interpreted as an empty block list.
            return $this->readDatabase($connection);
        }
    }

    /** @return list<array{network: string, expires_at: ?int}> */
    private function readDatabase(Connection $connection): array
    {
        return $connection->table('access_ip_blocks')->useWritePdo()->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['network', 'expires_at'])->map(fn ($row) => [
                'network' => $row->network,
                'expires_at' => $row->expires_at === null ? null : CarbonImmutable::parse($row->expires_at)->getTimestamp(),
            ])->all();
    }
}
