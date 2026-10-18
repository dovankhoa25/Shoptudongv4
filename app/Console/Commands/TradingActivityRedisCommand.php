<?php

namespace App\Console\Commands;

use App\Jobs\PublishTradingBotActivities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, Redis};

/** Diagnoses and, explicitly, removes only the dedicated ephemeral activity backlog. */
class TradingActivityRedisCommand extends Command
{
    protected $signature = 'trading:activity-redis
        {--clear-backlog : Xóa riêng payload job hoạt động đã kiểm tra; không xóa queue khác}
        {--clear-tool-cache : Xóa riêng cache hoạt động và policy ngọc cũ của tool trên Redis}
        {--clear-activity-cache : Alias clear-tool-cache cho cache tool cũ}
        {--cache-store=redis : Cache store Redis cũ cần dọn trạng thái hoạt động}';
    protected $description = 'Đọc bộ nhớ Redis và backlog hoạt động bot; mặc định không thay đổi dữ liệu';

    public function handle(): int
    {
        $queue = (string) config('trading_v3.activity_queue', 'trading-activity');
        $queueConnection = (string) config('trading_v3.activity_queue_connection', 'redis');
        if (config('queue.connections.'.$queueConnection.'.driver') !== 'redis') {
            $this->error('Connection queue hoạt động không dùng Redis; không thao tác queue khác.');
            return self::FAILURE;
        }
        if (!preg_match('/^trading-activity(?:[-_][a-zA-Z0-9_-]+)?$/D', $queue)) {
            $this->error('Chỉ hỗ trợ queue riêng trading-activity; từ chối queue default hoặc queue dùng chung.');
            return self::FAILURE;
        }
        try {
            $redis = Redis::connection(config('queue.connections.'.$queueConnection.'.connection', 'default'));
            $info = $redis->info('memory');
            if (isset($info['Memory']) && is_array($info['Memory'])) $info = $info['Memory'];
            $this->table(['Thông số', 'Giá trị'], collect(['used_memory_human', 'used_memory', 'maxmemory_human', 'maxmemory', 'maxmemory_policy'])
                ->map(fn ($key) => [$key, (string) ($info[$key] ?? 'không có')])->all());
            $keys = ['pending' => 'queues:'.$queue, 'reserved' => 'queues:'.$queue.':reserved',
                'delayed' => 'queues:'.$queue.':delayed', 'notify' => 'queues:'.$queue.':notify'];
            $counts = $this->counts($redis, $keys);
            $this->table(['Queue '.$queue, 'Số lượng'], collect($counts)->map(fn ($count, $name) => [$name, $count])->all());
            if (!$this->option('clear-backlog') && !$this->option('clear-activity-cache') && !$this->option('clear-tool-cache')) {
                $this->info('Chỉ đọc. Chỉ dùng --clear-backlog sau khi backend đã chuyển hoạt động sang after_response.');
                return self::SUCCESS;
            }
            if (config('trading_v3.activity_dispatch', 'after_response') !== 'after_response') {
                $this->error('Chuyển activity_dispatch=after_response và nạp lại config trước khi dọn backlog.');
                return self::FAILURE;
            }
            if ($this->option('clear-backlog') && !$this->clearBacklog($redis, $keys, $counts)) return self::FAILURE;
            if (($this->option('clear-activity-cache') || $this->option('clear-tool-cache')) && !$this->clearActivityCache()) return self::FAILURE;
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Không đọc/xử lý được Redis ('.class_basename($e).'). Không in thông tin kết nối hoặc mật khẩu.');
            return self::FAILURE;
        }
    }

    private function verified(array $values): bool
    {
        foreach ($values as $payload) {
            $data = json_decode($payload, true);
            if (!is_array($data) || ($data['data']['commandName'] ?? null) !== PublishTradingBotActivities::class) return false;
        }
        return true;
    }

    private function page($redis, string $name, string $key, int $start, int $end): array
    {
        return $name === 'pending' ? $redis->lrange($key, $start, $end) : $redis->zrange($key, $start, $end);
    }

    private function clearBacklog($redis, array $keys, array $counts): bool
    {
        // Preflight the entire existing backlog before any removal, without unserializing jobs.
        foreach (['pending', 'reserved', 'delayed'] as $name) {
            for ($offset = 0; $offset < $counts[$name]; $offset += 10) {
                if (!$this->verified($this->page($redis, $name, $keys[$name], $offset, $offset + 9))) {
                    $this->error('Phát hiện job khác hoặc payload không xác định; chưa xóa payload nào.');
                    return false;
                }
            }
        }
        $removed = 0;
        foreach (['pending', 'reserved', 'delayed'] as $name) {
            for ($processed = 0; $processed < $counts[$name];) {
                $values = $this->page($redis, $name, $keys[$name], 0, min(9, $counts[$name] - $processed - 1));
                if (!$values) break;
                if (!$this->verified($values)) {
                    $this->error('Queue thay đổi và xuất hiện job khác; dừng dọn. Chỉ payload hoạt động đã kiểm tra có thể đã được xóa.');
                    return false;
                }
                // Outside MULTI these memory-releasing commands work even under Redis maxmemory OOM.
                // Exact bytes protect a concurrently replaced/new foreign job. LREM count=1 scans from the head.
                if ($name === 'pending') foreach ($values as $value) $removed += (int) $redis->lrem($keys[$name], 1, $value);
                else $removed += (int) $redis->zrem($keys[$name], ...$values);
                $processed += count($values);
            }
        }
        $remaining = $this->counts($redis, $keys);
        if ($remaining['pending'] || $remaining['reserved'] || $remaining['delayed']) {
            $this->warn('Đã xóa '.$removed.' payload hoạt động; queue còn thay đổi. Không xóa job khác hoặc notify. Dừng producer/worker cũ rồi chạy lại.');
            return false;
        }
        $redis->del($keys['notify']);
        $this->info('Đã xóa '.$removed.' payload hoạt động và notify của queue riêng. Không xóa queue/cache/session khác hoặc dữ liệu đơn.');
        return true;
    }

    private function clearActivityCache(): bool
    {
        $name = (string) $this->option('cache-store');
        if (config('cache.stores.'.$name.'.driver') !== 'redis') {
            $this->error('Cache store chỉ định không dùng Redis; không dọn cache chung.');
            return false;
        }
        $store = Cache::store($name)->getStore();
        if (!$store instanceof \Illuminate\Cache\RedisStore) {
            $this->error('Không xác định được prefix/cache connection Redis; không xóa.');
            return false;
        }
        $connection = $store->connection(); $client = $connection->client();
        if ($connection instanceof \Illuminate\Redis\Connections\PredisClusterConnection || $connection instanceof \Illuminate\Redis\Connections\PhpRedisClusterConnection) {
            $this->error('Chưa hỗ trợ quét Redis cluster; không xóa cache.');
            return false;
        }
        if ($client instanceof \Predis\Client) $redisPrefix = (string) ($client->getOptions()->prefix ?? '');
        elseif ($client instanceof \Redis) $redisPrefix = (string) $client->getOption(\Redis::OPT_PREFIX);
        else { $this->error('Redis client chưa được hỗ trợ để xác minh prefix; không xóa.'); return false; }
        $cachePrefix = $store->getPrefix(); $scope = $redisPrefix.$cachePrefix.'trading-v3:activity:';
        $pattern = strtr($scope, ['\\'=>'\\\\','*'=>'\\*','?'=>'\\?','['=>'\\[',']'=>'\\]']).'*';
        $cursor = '0'; $removed = 0; $iterations = 0;
        do {
            $arguments = ['SCAN',$cursor,'MATCH',$pattern,'COUNT','100'];
            $scan = $client instanceof \Predis\Client ? $client->executeRaw($arguments) : $client->rawCommand(...$arguments);
            if (!is_array($scan) || count($scan) !== 2 || !is_array($scan[1])) throw new \RuntimeException('Invalid isolated activity scan response.');
            $cursor = (string) $scan[0];
            foreach ($scan[1] as $rawKey) {
                if (!is_string($rawKey) || !str_starts_with($rawKey, $redisPrefix)) continue;
                $logicalKey = substr($rawKey, strlen($redisPrefix));
                if (!preg_match('/^'.preg_quote($cachePrefix.'trading-v3:activity:', '/').'(?:bot|gem_bot):[1-9][0-9]*$/D', $logicalKey)) continue;
                $removed += (int) $connection->del($logicalKey);
            }
            if (++$iterations > 100000) throw new \RuntimeException('Activity scan iteration limit reached.');
        } while ($cursor !== '0');
        $removed += (int) $connection->del($cachePrefix.'trading-v3:gem-timeout-policy');
        $this->info('Đã xóa '.$removed.' cache hoạt động/policy ngọc cũ. Không xóa nonce HMAC, session, tồn kho, đơn hoặc cache khác.');
        return true;
    }

    private function counts($redis, array $keys): array
    {
        return ['pending' => (int) $redis->llen($keys['pending']), 'reserved' => (int) $redis->zcard($keys['reserved']),
            'delayed' => (int) $redis->zcard($keys['delayed']), 'notify' => (int) $redis->llen($keys['notify'])];
    }
}
