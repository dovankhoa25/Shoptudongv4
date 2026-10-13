<?php

namespace App\Services\TradingV3;

use App\Models\{Bot, GemBot, GemTransaction, GoldTransaction, InventoryMovement};
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Lease acknowledgements contain no credentials, inventory resources or workflow writes. */
class BatchHeartbeatService
{
    public function renew(array $data): array
    {
        $interval = (int) ($data['renewal_interval_seconds'] ?? config('trading_v3.renewal_interval_seconds', 150));
        $interval = max(10, min((int) config('trading_v3.max_renewal_interval_seconds', 3600), $interval));
        $leaseSeconds = max(90, $interval + max(30, (int) config('trading_v3.lease_margin_seconds', 60)));
        $worker = $data['worker_id'];
        $gemIds = $this->sortedIds($data['gem_bot_ids'] ?? []);
        $goldIds = $this->sortedIds($data['gold_bot_ids'] ?? []);

        return DB::transaction(function () use ($worker, $interval, $leaseSeconds, $gemIds, $goldIds): array {
            $now = now();
            $until = $now->copy()->addSeconds($leaseSeconds);
            // Every lease decision holds bot rows first. Gold transfers use the same ascending-ID order.
            // Do not lock a workflow row before a bot row: claim/receipt paths already lock bots first.
            $gems = $gemIds ? GemBot::with('server:id,status')->whereKey($gemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            $gold = $goldIds ? Bot::with(['server:id,status', 'loginServer:id,ip,port'])->whereKey($goldIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            $gemOrders = $gemIds ? GemTransaction::whereIn('bot_id', $gemIds)->where('status', 'processing')
                ->get(['bot_id', 'processing_version', 'processing_owner'])->groupBy('bot_id') : collect();
            $goldOrders = $goldIds ? GoldTransaction::whereIn('bot_id', $goldIds)->where('status', 'processing')
                ->get(['bot_id', 'processing_version', 'processing_owner'])->groupBy('bot_id') : collect();
            $transfers = $goldIds ? InventoryMovement::where('transaction_type', 'gold_transfer')->where('movement_type', 'reservation')
                ->where('meta->status', 'claimed')
                ->where(fn ($query) => $query->whereIn('meta->from_bot_id', $goldIds)->orWhereIn('meta->to_bot_id', $goldIds))
                ->get(['meta']) : collect();
            $transferOwners = [];
            foreach ($transfers as $transfer) {
                foreach (['from_bot_id', 'to_bot_id'] as $key) {
                    $transferOwners[(int) $transfer->meta[$key]][] = $transfer->meta['processing_owner'] ?? null;
                }
            }

            $acceptedGem = $acceptedGold = $gemAcks = $goldAcks = [];
            $gemVisibilityChanged = $goldVisibilityChanged = false;
            foreach ($gemIds as $id) {
                $bot = $gems->get($id);
                $code = $this->eligibility($bot, $worker, false);
                if ($code === 'renewed') {
                    foreach ($gemOrders->get($id, collect()) as $order) {
                        if ($order->processing_version === null || (int) $order->processing_version === 2) { $code = 'legacy_order'; break; }
                        if ($order->processing_owner !== null && $order->processing_owner !== $worker) { $code = 'unfinished_order'; break; }
                    }
                }
                if ($code === 'renewed') {
                    $acceptedGem[] = $id;
                    $gemVisibilityChanged = $gemVisibilityChanged || !$bot->v3_worker_id || !$bot->v3_lease_until?->isFuture()
                        || $until->lt($bot->v3_lease_until);
                }
                $gemAcks[] = $this->ack($id, $code, $until);
            }
            foreach ($goldIds as $id) {
                $bot = $gold->get($id);
                $code = $this->eligibility($bot, $worker, true);
                if ($code === 'renewed') {
                    foreach ($goldOrders->get($id, collect()) as $order) {
                        if ((int) $order->processing_version !== 3 || $order->processing_owner !== $worker) { $code = 'unfinished_order'; break; }
                    }
                }
                if ($code === 'renewed') {
                    foreach ($transferOwners[$id] ?? [] as $owner) {
                        if ($owner !== $worker) { $code = 'unfinished_transfer'; break; }
                    }
                }
                if ($code === 'renewed') {
                    $acceptedGold[] = $id;
                    $goldVisibilityChanged = $goldVisibilityChanged || !$bot->v3_worker_id || !$bot->v3_lease_until?->isFuture()
                        || $until->lt($bot->v3_lease_until);
                }
                $goldAcks[] = $this->ack($id, $code, $until);
            }

            // Two bulk writes at most. Model observers/history are intentionally not invoked by a lease renewal.
            if ($acceptedGem) GemBot::whereKey($acceptedGem)->update(['v3_worker_id' => $worker, 'v3_lease_until' => $until, 'updated_at' => $now]);
            if ($acceptedGold) Bot::whereKey($acceptedGold)->update(['v3_worker_id' => $worker, 'v3_lease_until' => $until, 'updated_at' => $now]);
            if ($gemVisibilityChanged) ApiCache::clearGroups(['public:gembot', 'public:server-prices']);
            if ($goldVisibilityChanged) ApiCache::clearGroups(['public:bots']);

            return ['server_time' => $now->toIso8601String(), 'renewal_interval_seconds' => $interval,
                'lease_seconds' => $leaseSeconds, 'gem_bots' => $gemAcks, 'gold_bots' => $goldAcks];
        }, 3);
    }

    private function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function eligibility(?Model $bot, string $worker, bool $gold): string
    {
        if (!$bot) return 'not_found';
        if (!$bot->status) return 'disabled';
        if (!$bot->server?->status) return 'server_disabled';
        if ($gold) {
            $login = $bot->loginServer;
            if (!$login || trim((string) $login->ip) === '' || (int) $login->port < 1 || (int) $login->port > 65535) return 'login_invalid';
        }
        if ($bot->v3_worker_id && $bot->v3_worker_id !== $worker && $bot->v3_lease_until?->isFuture()) return 'owned_elsewhere';
        return 'renewed';
    }

    private function ack(int $id, string $code, $until): array
    {
        return ['id' => $id, 'granted' => $code === 'renewed', 'code' => $code,
            'lease_until' => $code === 'renewed' ? $until->toIso8601String() : null];
    }
}
