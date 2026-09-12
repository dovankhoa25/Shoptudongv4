<?php
namespace App\Services;

use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use App\Models\User;
use App\Support\ApiCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NroShopService
{
    public static function require(bool $ok, string $message): void
    {
        if (!$ok) throw ValidationException::withMessages(['nro' => $message]);
    }
    public function listing(object $listing): array
    {
        return $this->listings([$listing])[$listing->id];
    }

    /**
     * Describe many listings with a fixed number of queries instead of ~8 per row.
     * Returns payloads keyed by listing id, in the order the rows were given.
     */
    public function listings(iterable $rows): array
    {
        $rows = collect($rows)->keyBy('id');
        if ($rows->isEmpty()) return [];

        $accountIds = $rows->pluck('account_id')->filter()->unique()->values();
        $accounts = NroAccount::whereIn('id', $accountIds)->get()->keyBy('id');
        $snapshots = NroAccountSnapshot::whereIn('id', $accounts->pluck('latest_snapshot_id')->filter()->unique())->get()->keyBy('id');
        $servers = DB::table('servers')->whereIn('id', $accounts->pluck('server_id')->filter()->unique())->pluck('name_view', 'id');
        $itemsByListing = DB::table('item_listing_items as li')->join('nro_inventory_items as i', 'i.id', '=', 'li.inventory_item_id')
            ->whereIn('li.listing_id', $rows->keys())
            ->select('li.listing_id', 'i.item_json', 'i.quantity as stock', 'i.reserved', 'li.quantity', 'i.id')->get()->groupBy('listing_id');
        [$allocatedTotals, $ownShares] = NroListingStock::allocationMaps($accountIds);
        // One global fact, not one lookup per listing.
        $workerOnline = DB::table('nro_worker_keys')->where('accepts_delivery', true)->whereNull('revoked_at')
            ->where('last_used_at', '>', now()->subSeconds(90))->exists();
        $busyByAccount = DB::table('nro_worker_jobs')->whereIn('account_id', $accountIds)->whereIn('status', ['processing', 'review'])
            ->get(['account_id', 'status', 'type'])->groupBy('account_id')->map(fn ($jobs) => $jobs->filter(fn ($j) => $j->status === 'review' || $j->type === 'snapshot')->pluck('status'));
        // Ascending id so the highest id overwrites earlier ones: the latest order per listing.
        $lastOrderStatus = DB::table('item_orders')->whereIn('listing_id', $rows->keys())->orderBy('id')->pluck('status', 'listing_id');
        $policy = NroListingStock::policy();

        return $rows->map(function ($listing) use ($accounts, $snapshots, $servers, $itemsByListing, $allocatedTotals, $ownShares, $busyByAccount, $lastOrderStatus, $workerOnline, $policy) {
            $account = $accounts->get($listing->account_id);

            return $this->listingPayload($listing, $account,
                $account?->latest_snapshot_id ? $snapshots->get($account->latest_snapshot_id) : null,
                $itemsByListing->get($listing->id) ?? collect(),
                NroListingStock::allocationExcept($allocatedTotals[(int) $listing->account_id] ?? [], $ownShares[(int) $listing->id] ?? []),
                $busyByAccount->get($listing->account_id) ?? collect(),
                $lastOrderStatus[$listing->id] ?? null,
                $account?->server_id ? ($servers[$account->server_id] ?? null) : null,
                $workerOnline, $policy);
        })->all();
    }

    private function listingPayload(object $listing, ?NroAccount $account, ?NroAccountSnapshot $snapshot, Collection $items,
        array $allocated, Collection $busy, ?string $lastOrderStatus, ?string $serverName, bool $workerOnline, array $policy): array
    {
        $available = $items->isEmpty() ? 0 : $items->min(fn ($i) => intdiv(max(0, $i->stock - $i->reserved - ($allocated[$i->id] ?? 0)), $i->quantity));
        $fresh = $account?->server_id && $account?->server_game_id && $snapshot && ($snapshot->completeness_json['bag'] ?? false) && ($snapshot->completeness_json['chest'] ?? false) && ($snapshot->completeness_json['equipped'] ?? false)
            && $account->last_synced_at !== null
            && $snapshot->captured_at->lte(now()->addMinutes(5)) && $account->status === 'active' && $account->usage_type === 'warehouse';
        $reasons = [];
        if ($account?->login_sale_blocked) $reasons[] = 'Kho đang cần sửa thông tin đăng nhập';
        if ($account?->shop_hidden) $reasons[] = 'Kho đang tạm ẩn khỏi shop; đơn đã mua vẫn được giao';
        if ($account?->status === 'demo') $reasons[] = 'Dữ liệu demo · không giao dịch thật';
        if ($items->contains(fn ($i) => !NroListingStock::allows((int) json_decode($i->item_json, true)['templateId'], $policy))) $reasons[] = 'Gói chứa vật phẩm ngoài danh sách được phép bán';
        if ($listing->status !== 'active') $reasons[] = $listing->status === 'sold' ? 'Gói đã có người mua' : 'Gói đang tạm dừng';
        if ($busy->contains('review')) $reasons[] = 'Kho đang chờ đối soát';
        elseif ($busy->contains('processing')) $reasons[] = 'Kho đang xử lý công việc';
        if (!$fresh) {
            if (!$account || $account->status !== 'active' || $account->usage_type !== 'warehouse') $reasons[] = 'Acc không phải kho đang hoạt động';
            elseif (!$account->server_id || !$account->server_game_id) $reasons[] = 'Kho chưa cấu hình đủ server';
            elseif (!$snapshot) $reasons[] = 'Kho chưa lấy dữ liệu lần đầu';
            elseif (!($snapshot->completeness_json['bag'] ?? false) || !($snapshot->completeness_json['chest'] ?? false)) $reasons[] = 'Chưa xác nhận đủ hành trang và rương; tool sẽ lấy lại';
            elseif ($snapshot->captured_at->gt(now()->addMinutes(5))) $reasons[] = 'Thời gian snapshot không hợp lệ';
            else $reasons[] = 'Đang chờ tool cập nhật tồn kho sau thay đổi';
        }
        if (!$workerOnline) $reasons[] = 'Tool giao đồ đang offline';
        if ($available === 0) {
            $physical = $items->isEmpty() ? 0 : $items->min(fn ($i) => intdiv(max(0, $i->stock), $i->quantity));
            $reasons[] = $physical > 0 ? (array_sum($allocated) > 0 ? 'Đồ đã được phân cho gói khác hoặc đơn chưa nhận' : 'Đồ đã được giữ cho đơn chưa nhận') : 'Không đủ đồ cho một gói';
        }

        return ['id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description,
            'shopHidden' => (bool) $account?->shop_hidden, 'price' => (string) $listing->price, 'status' => $listing->status, 'lastOrderStatus' => $lastOrderStatus,
            'serverIndex' => $account?->server_index, 'serverId' => $account?->server_id, 'serverName' => $serverName,
            'available' => !$reasons ? min(1, $available) : 0, 'stockAvailable' => $available, 'unavailableReasons' => $reasons, 'workerOnline' => $workerOnline,
            'needsSync' => !$fresh, 'items' => $items->map(fn ($i) => ['inventoryItemId' => $i->id, 'quantity' => $i->quantity, 'item' => json_decode($i->item_json, true)])->all()];
    }

    public function purchase(User $buyer, int $listingId, string $name, int $server, string $key): int
    {
        return DB::transaction(function () use ($buyer, $listingId, $name, $server, $key) {
            $buyer = User::whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('item_orders')->where(['buyer_id' => $buyer->id, 'request_key' => $key])->first();
            if ($existing) {
                self::require($existing->listing_id == $listingId && $existing->recipient_name === $name && $existing->server_id == $server, 'Mã yêu cầu đã được dùng cho đơn khác.');
                return $existing->id;
            }
            $listing = DB::table('item_listings')->where('id', $listingId)->first(); abort_unless($listing, 404);
            $account = NroAccount::whereKey($listing->account_id)->lockForUpdate()->firstOrFail();
            $listing = DB::table('item_listings')->where('id', $listingId)->lockForUpdate()->first();
            self::require(!DB::table('item_orders')->where('listing_id', $listingId)->exists(), 'Gói đồ đã được mua.');
            self::require(!$account->login_sale_blocked, 'Kho đang cần sửa thông tin đăng nhập.');
            self::require(!$account->shop_hidden, 'Kho đang tạm ẩn khỏi shop.');
            self::require($listing->user_id != $buyer->id, 'Không thể mua gói đồ của chính bạn.');
            self::require($account->server_id == $server, 'Nhân vật nhận phải ở cùng server với gói đồ.');
            self::require($this->listing($listing)['available'] > 0, 'Gói đồ hết hàng hoặc cần đồng bộ kho.');
            self::require(!DB::table('nro_worker_jobs')->where('account_id', $account->id)->where(function ($q) { $q->where('status', 'review')->orWhere(fn ($j) => $j->where('status', 'processing')->where('type', 'snapshot')); })->exists(), 'Acc đang lấy dữ liệu hoặc chờ đối soát. Vui lòng thử lại sau.');
            self::require((int) $buyer->balance >= $listing->price, 'Số dư không đủ.');
            $order = DB::table('item_orders')->insertGetId(['buyer_id' => $buyer->id, 'seller_id' => $listing->user_id, 'listing_id' => $listingId,
                'account_id' => $account->id, 'recipient_name' => $name, 'server_index' => $account->server_index ?? 0, 'server_id' => $server, 'price' => $listing->price,
                'title' => $listing->title, 'request_key' => $key, 'status' => 'awaiting_receipt', 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('item_listing_items')->where('listing_id', $listingId)->orderBy('inventory_item_id')->get() as $line) {
                $item = DB::table('nro_inventory_items')->where('id', $line->inventory_item_id)->lockForUpdate()->first();
                self::require($item && $item->quantity - $item->reserved >= $line->quantity, 'Không đủ đồ cho cả gói.');
                DB::table('nro_inventory_items')->where('id', $item->id)->increment('reserved', $line->quantity);
                DB::table('item_order_items')->insert(['order_id' => $order, 'inventory_item_id' => $item->id, 'quantity' => $line->quantity, 'item_json' => $item->item_json]);
                DB::table('item_inventory_reservations')->insert(['order_id' => $order, 'inventory_item_id' => $item->id, 'quantity' => $line->quantity, 'status' => 'held', 'created_at' => now(), 'updated_at' => now()]);
            }
            $before = (int) $buyer->balance; $buyer->decrement('balance', $listing->price);
            TransactionService::log(userId: $buyer->id, type: 'buy_nro_items', amount: -$listing->price, description: "Mua gói đồ #$order", related: 'nro_item_order', relatedId: $order, oldBalance: $before, newBalance: $before - $listing->price, idempotencyKey: "nro-order:$order:debit");
            DB::table('item_listings')->where('id', $listingId)->update(['status' => 'sold', 'updated_at' => now()]);
            ApiCache::clearGroups(['public:nro-shop:listings']);
            return $order;
        }, 3);
    }

    public function order(int $id): array
    {
        $orders = $this->orders([$id]); abort_unless(isset($orders[$id]), 404);

        return $orders[$id];
    }

    /**
     * Describe many orders with a fixed number of queries instead of ~5 per row.
     * Returns payloads keyed by order id; ids with no matching row are simply absent.
     */
    public function orders(iterable $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) return [];

        $rows = DB::table('item_orders')->whereIn('id', $ids)->get()->keyBy('id');
        if ($rows->isEmpty()) return [];
        // Ascending id so the highest id overwrites earlier ones: the latest session per order.
        $sessions = DB::table('nro_delivery_sessions')->whereIn('id',DB::table('nro_delivery_sessions')->selectRaw('MAX(id)')->whereIn('order_id',$rows->keys())->groupBy('order_id'))->get()->keyBy('order_id');
        $accounts = NroAccount::whereIn('id', $rows->pluck('account_id')->filter()->unique())->get()->keyBy('id');
        $servers = DB::table('servers')->whereIn('id', $rows->pluck('server_id')->filter()->unique())->pluck('name_view', 'id');
        $itemsByOrder = DB::table('item_order_items')->whereIn('order_id', $rows->keys())->get()->groupBy('order_id');

        $activity = DB::table('nro_worker_jobs as j')->join('nro_delivery_sessions as s', 's.id', '=', 'j.delivery_session_id')
            ->whereIn('j.account_id', $rows->pluck('account_id')->unique())->where('j.status', 'processing')
            ->get(['j.account_id', 'j.order_id', 's.status', 's.position_json', 'j.worker_instance', 'j.lease_until'])->groupBy('account_id');
        return $rows->map(function ($o) use ($sessions, $accounts, $servers, $itemsByOrder, $activity) {
            $session = $this->sessionPayload($sessions->get($o->id));
            $location = $accounts->get($o->account_id);
            $live=($activity->get($o->account_id) ?? collect())->contains(fn($j)=>(int)$j->order_id===(int)$o->id && $j->lease_until && $j->lease_until>now()->toDateTimeString());
            if($session && !$live) $session['position']=null;
            $delivered=($itemsByOrder->get($o->id) ?? collect())->sum('delivered');

            return ['id' => $o->id, 'revision'=>(int)$o->realtime_revision, 'title' => $o->title, 'price' => (string) $o->price, 'status' => $o->status,
                'recipientName' => $session['recipientName'] ?? $o->recipient_name, 'serverIndex' => $o->server_index, 'serverId' => $o->server_id,
                'serverName' => $servers[$o->server_id] ?? null, 'message' => $o->status==='processing' && !$live ? 'Bot mất liên lạc; chờ khôi phục và xác nhận lại điểm nhận.' : ($o->public_failure ?: $o->delivery_message),
                'botLeaseUntil'=>($lease=($activity->get($o->account_id) ?? collect())->where('order_id',$o->id)->max('lease_until')) ? \Carbon\Carbon::parse($lease)->toIso8601String() : null,'botOnline'=>$live,'failureCode'=>$o->failure_code,'publicFailure'=>$o->public_failure,'loginRetryAt'=>$o->login_retry_at ? \Carbon\Carbon::parse($o->login_retry_at)->toIso8601String() : null,'cancelRequested'=>(bool)$o->cancel_requested,'canCancel'=>!$delivered && !$o->cancel_requested && !in_array($o->status,['review','completed','refunded']) && NroOrderRefund::eligible($o),'refundRequested' => (bool)$o->refund_requested, 'refundAmount' => (int)$o->refund_amount, 'refundedAt' => $o->refunded_at, 'session' => $session,
                // Counts and bot identity are public; other buyers' names/order IDs are never exposed.
                'botActivity' => array_merge($location ? NroWarehouseActivity::publicPayload($location, $activity->get($o->account_id) ?? collect()) : [], [
                    'waitingCount' => ($activity->get($o->account_id) ?? collect())->whereIn('status', ['preparing', 'ready'])->count(),
                    'servingOther' => ($activity->get($o->account_id) ?? collect())->contains(fn ($a) => $a->status === 'trading' && $a->order_id != $o->id),
                ]),
                'deliveryLocation' => $location ? ['mapId' => $location->delivery_map, 'mapName' => $location->delivery_map == 5 ? 'Đảo Kame' : null, 'zoneMode' => $location->delivery_zone_mode, 'zone' => $location->delivery_zone] : null,
                'createdAt' => $o->created_at, 'items' => ($itemsByOrder->get($o->id) ?? collect())->map(fn ($i) => [
                    'id' => $i->id, 'item' => json_decode($i->item_json, true), 'quantity' => $i->quantity, 'delivered' => $i->delivered])->values()->all()];
        })->all();
    }

    private function sessionPayload(?object $s): ?array
    {
        return $s ? ['id' => $s->id, 'mode' => $s->mode, 'status' => $s->status,
            'recipientName' => $s->recipient_name, 'readyAt' => $s->ready_at ? \Carbon\Carbon::parse($s->ready_at)->toIso8601String() : null,
            'expiresAt' => $s->expires_at ? \Carbon\Carbon::parse($s->expires_at)->toIso8601String() : null,
            'retryAt' => $s->retry_at ? \Carbon\Carbon::parse($s->retry_at)->toIso8601String() : null,
            'tradePhase' => $s->trade_phase, 'phaseDeadline' => $s->phase_deadline ? \Carbon\Carbon::parse($s->phase_deadline)->toIso8601String() : null,
            'position' => json_decode($s->position_json ?? 'null', true)] : null;
    }

    public function settle(int $orderId, bool $delivered): void
    {
        $order = DB::table('item_orders')->where('id', $orderId)->lockForUpdate()->first();
        if (in_array($order->status, ['completed', 'refunded'])) return;
        self::require($delivered, 'Hoàn tiền chỉ được thực hiện qua thao tác admin.');
        // Only called after confirmed worker result or explicit administrator reconciliation.
        foreach (DB::table('item_inventory_reservations')->where('order_id', $orderId)->where('status', 'held')->get() as $r) {
            DB::table('nro_inventory_items')->where('id', $r->inventory_item_id)->decrement('reserved', $r->quantity);
        }
        DB::table('item_inventory_reservations')->where('order_id', $orderId)->update(['status' => 'consumed', 'updated_at' => now()]);
        $user = User::whereKey($order->seller_id)->lockForUpdate()->firstOrFail();
        self::require((int) $user->balance <= TransactionService::MAX_BALANCE - $order->price, 'Số dư vượt giới hạn; cần đối soát.');
        $before = (int) $user->balance; $user->increment('balance', $order->price);
        TransactionService::log(userId: $user->id, type: 'sell_nro_items', amount: $order->price,
            description: 'Bán'.' gói đồ #'.$orderId, related: 'nro_item_order', relatedId: $orderId,
            oldBalance: $before, newBalance: $before + $order->price, idempotencyKey: "nro-order:$orderId:settle");
        DB::table('item_orders')->where('id', $orderId)->update(['status' => 'completed', 'failure_code'=>null,'public_failure'=>null,'login_retry_at'=>null,'cancel_requested'=>false,'refund_requested' => false, 'updated_at' => now()]);
        if ($delivered) DB::table('item_order_items')->where('order_id', $orderId)->update(['delivered' => DB::raw('quantity')]);
        // Force a fresh inventory before the next sale after manual settlement.
        NroAccount::whereKey($order->account_id)->update(['last_synced_at' => null]);
        ApiCache::clearGroups(['public:nro-shop:listings']);
    }
}
