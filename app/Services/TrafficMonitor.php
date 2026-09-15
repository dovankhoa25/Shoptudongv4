<?php
namespace App\Services;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

final class TrafficMonitor
{
    private function store()
    {
        return Cache::store(config('traffic_monitor.store'));
    }

    private function key(int $minute, int $shard): string
    {
        $slot = $minute % ((int) config('traffic_monitor.minutes') + 2);
        return "traffic:v1:{$slot}:{$shard}";
    }

    /** Display attribution only; never changes the request IP used by auth/throttling. */
    public function client(Request $request): array
    {
        $peer = (string) $request->server('REMOTE_ADDR', '');
        $validPeer = filter_var($peer, FILTER_VALIDATE_IP);
        $forwarded = $request->header('CF-Connecting-IP');
        if ($validPeer && is_string($forwarded) && filter_var($forwarded, FILTER_VALIDATE_IP)
            && IpUtils::checkIp($peer, config('traffic_monitor.cloudflare_proxies', []))) {
            return ['ip' => $forwarded, 'source' => 'cloudflare'];
        }
        return ['ip' => $validPeer ? $peer : 'unknown', 'source' => 'peer'];
    }

    public function record(RequestHandled $event): void
    {
        if (!config('traffic_monitor.enabled')) return;
        // The monitoring page must not inflate its own numbers.
        if ($event->request->route()?->getName() === 'admin.traffic.index') return;
        try {
            $request = $event->request;
            $client = $this->client($request);
            $route = $request->route()?->uri() ?? '[unmatched]';
            $method = in_array($request->method(), ['GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD'], true)
                ? $request->method() : 'OTHER';
            // HandleCors returns before routing. Do not mislabel preflight as a missing route,
            // or record the raw URL/headers (unbounded cardinality and possible secrets).
            if ($route === '[unmatched]' && $method === 'OPTIONS'
                && $request->headers->has('Origin') && $request->headers->has('Access-Control-Request-Method')) {
                $route = '[preflight]';
            }
            $status = $event->response->getStatusCode();
            $group = match (true) {
                $request->is('api/*') => 'api',
                $request->is('app/*') => 'worker',
                $request->is('webhooks/*') => 'webhook',
                $request->is('admin', 'admin/*') => 'admin',
                default => 'web',
            };
            $started = $request->attributes->get('_traffic_started');
            $ms = $started ? max(0, (int) round((hrtime(true) - $started) / 1e6)) : 0;
            $identity = hash('sha256', json_encode([$client, $route, $method, $status, $group]));
            $minute = intdiv(now()->timestamp, 60);
            $shard = hexdec(substr($identity, 0, 4)) % (int) config('traffic_monitor.shards');
            $key = $this->key($minute, $shard);
            $store = $this->store();
            $lock = $store->lock($key.':lock', 3);
            // Never queue website requests behind metrics writes. These are approximate counters.
            if (!$lock->get()) return;
            try {
                $bucket = $store->get($key);
                if (($bucket['minute'] ?? null) !== $minute) $bucket = ['minute' => $minute, 'rows' => [], 'overflow' => 0];
                if (!isset($bucket['rows'][$identity]) && count($bucket['rows']) >= config('traffic_monitor.series_per_shard')) {
                    $bucket['overflow']++;
                } else {
                    $row = $bucket['rows'][$identity] ?? [
                        ...$client, 'route' => $route, 'method' => $method, 'status' => $status,
                        'group' => $group, 'count' => 0, 'total_ms' => 0, 'max_ms' => 0, 'last_seen' => 0,
                    ];
                    $row['count']++;
                    $row['total_ms'] += $ms;
                    $row['max_ms'] = max($row['max_ms'], $ms);
                    $row['last_seen'] = now()->timestamp;
                    $bucket['rows'][$identity] = $row;
                }
                $store->put($key, $bucket, ((int) config('traffic_monitor.minutes') + 2) * 60);
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // Observability failure must not change a payment/auth/worker response or flood logs.
        }
    }

    public function snapshot(int $minutes, string $ip = '', string $group = '', string $status = ''): array
    {
        $minutes = max(1, min((int) config('traffic_monitor.minutes'), $minutes));
        $now = now()->timestamp;
        $minute = intdiv($now, 60);
        $keys = [];
        for ($offset = 0; $offset < $minutes; $offset++) {
            for ($shard = 0; $shard < config('traffic_monitor.shards'); $shard++) {
                $keys[] = $this->key($minute - $offset, $shard);
            }
        }
        $rows = [];
        $overflow = 0;
        $available = true;
        try {
            foreach ($this->store()->many($keys) as $bucket) {
                if (!$bucket || $bucket['minute'] < $minute - $minutes + 1 || $bucket['minute'] > $minute) continue;
                $overflow += $bucket['overflow'];
                foreach ($bucket['rows'] as $id => $row) {
                    if (($ip !== '' && $row['ip'] !== $ip) || ($group !== '' && $row['group'] !== $group)
                        || ($status !== '' && (string) $row['status'] !== $status)) continue;
                    if (!isset($rows[$id])) $rows[$id] = $row;
                    else {
                        $rows[$id]['count'] += $row['count'];
                        $rows[$id]['total_ms'] += $row['total_ms'];
                        $rows[$id]['max_ms'] = max($rows[$id]['max_ms'], $row['max_ms']);
                        $rows[$id]['last_seen'] = max($rows[$id]['last_seen'], $row['last_seen']);
                    }
                }
            }
        } catch (\Throwable) {
            $available = false;
        }
        $totals = ['count' => 0, 'options' => 0, 'limited' => 0, 'auth_errors' => 0, 'not_found' => 0, 'server_errors' => 0, 'total_ms' => 0, 'max_ms' => 0];
        $ips = [];
        $endpoints = [];
        foreach ($rows as $row) {
            $totals['count'] += $row['count'];
            $totals['options'] += $row['method'] === 'OPTIONS' ? $row['count'] : 0;
            $totals['limited'] += $row['status'] === 429 ? $row['count'] : 0;
            $totals['auth_errors'] += in_array($row['status'], [401,403], true) ? $row['count'] : 0;
            $totals['not_found'] += $row['status'] === 404 ? $row['count'] : 0;
            $totals['server_errors'] += $row['status'] >= 500 ? $row['count'] : 0;
            $totals['total_ms'] += $row['total_ms'];
            $totals['max_ms'] = max($totals['max_ms'], $row['max_ms']);
            $ips[$row['ip']] = ($ips[$row['ip']] ?? 0) + $row['count'];
            $endpoint = $row['method'].' /'.$row['route'];
            $endpoints[$endpoint] = ($endpoints[$endpoint] ?? 0) + $row['count'];
        }
        arsort($ips);
        arsort($endpoints);
        uasort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);
        $top = fn ($items) => array_map(fn ($key, $count) => ['key' => (string) $key, 'count' => $count],
            array_keys(array_slice($items, 0, 10, true)), array_values(array_slice($items, 0, 10, true)));
        return [
            'enabled' => (bool) config('traffic_monitor.enabled'), 'available' => $available,
            'minutes' => $minutes, 'from' => ($minute - $minutes + 1) * 60, 'to' => $now,
            'totals' => $totals, 'ips' => $top($ips), 'endpoints' => $top($endpoints),
            'rows' => array_values(array_slice($rows, 0, 100)), 'overflow' => $overflow,
            'series' => count($rows), 'store' => config('traffic_monitor.store'),
        ];
    }
}
