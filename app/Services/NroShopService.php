<?php
namespace App\Services;

use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use App\Models\User;
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
        $account = NroAccount::find($listing->account_id);
        $items = DB::table('item_listing_items as li')->join('nro_inventory_items as i', 'i.id', '=', 'li.inventory_item_id')
            ->where('li.listing_id', $listing->id)->select('i.item_json', 'i.quantity as stock', 'i.reserved', 'li.quantity', 'i.id')->get();
        $allocated = NroListingStock::allocated($listing->account_id, $listing->id);
        $available = $items->isEmpty() ? 0 : $items->min(fn ($i) => intdiv(max(0, $i->stock - $i->reserved - ($allocated[$i->id] ?? 0)), $i->quantity));
        $snapshot = $account ? NroAccountSnapshot::find($account->latest_snapshot_id) : null;
        $fresh = $account?->server_id && $account?->server_game_id && $snapshot && ($snapshot->completeness_json['bag'] ?? false) && ($snapshot->completeness_json['chest'] ?? false)
            && $account->last_synced_at !== null
            && $snapshot->captured_at->lte(now()->addMinutes(5)) && $account->status === 'active' && $account->usage_type === 'warehouse';
        $workerOnline = DB::table('nro_worker_keys')->where('accepts_delivery', true)->whereNull('revoked_at')->where('last_used_at', '>', now()->subSeconds(90))->exists();
        $busy = DB::table('nro_worker_jobs')->where('account_id', $listing->account_id)->whereIn('status', ['processing', 'review'])->pluck('status');
        $reasons = [];
        $policy = NroListingStock::policy();
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
            'price' => (string) $listing->price, 'status' => $listing->status, 'serverIndex' => $account?->server_index, 'serverId' => $account?->server_id, 'serverName' => $account ? DB::table('servers')->where('id', $account->server_id)->value('name_view') : null,
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
            self::require($listing->user_id != $buyer->id, 'Không thể mua gói đồ của chính bạn.');
            self::require($account->server_id == $server, 'Nhân vật nhận phải ở cùng server với gói đồ.');
            self::require($this->listing($listing)['available'] > 0, 'Gói đồ hết hàng hoặc cần đồng bộ kho.');
            self::require(!DB::table('nro_worker_jobs')->where('account_id', $account->id)->whereIn('status', ['processing', 'review'])->exists(), 'Acc đang xử lý giao dịch hoặc chờ đối soát. Vui lòng thử lại sau.');
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
            return $order;
        }, 3);
    }

    public function order(int $id): array
    {
        $o = DB::table('item_orders')->where('id', $id)->first(); abort_unless($o, 404);
        $session = $this->session($id);
        $location = NroAccount::find($o->account_id);
        return ['id' => $o->id, 'title' => $o->title, 'price' => (string) $o->price, 'status' => $o->status,
            'recipientName' => $session['recipientName'] ?? $o->recipient_name, 'serverIndex' => $o->server_index, 'serverId' => $o->server_id, 'serverName' => DB::table('servers')->where('id', $o->server_id)->value('name_view'), 'message' => $o->delivery_message,
            'session' => $session,
            'deliveryLocation' => $location ? ['mapId' => $location->delivery_map, 'mapName' => $location->delivery_map == 5 ? 'Đảo Kame' : null, 'zoneMode' => $location->delivery_zone_mode, 'zone' => $location->delivery_zone] : null,
            'createdAt' => $o->created_at, 'items' => DB::table('item_order_items')->where('order_id', $id)->get()->map(fn ($i) => [
                'id' => $i->id, 'item' => json_decode($i->item_json, true), 'quantity' => $i->quantity, 'delivered' => $i->delivered])->all()];
    }

    private function session(int $orderId): ?array
    {
        $s = DB::table('nro_delivery_sessions')->where('order_id', $orderId)->orderByDesc('id')->first();
        return $s ? ['id' => $s->id, 'mode' => $s->mode, 'status' => $s->status,
            'recipientName' => $s->recipient_name, 'readyAt' => $s->ready_at ? \Carbon\Carbon::parse($s->ready_at)->toIso8601String() : null,
            'expiresAt' => $s->expires_at ? \Carbon\Carbon::parse($s->expires_at)->toIso8601String() : null,
            'position' => json_decode($s->position_json ?? 'null', true)] : null;
    }

    public function settle(int $orderId, bool $delivered): void
    {
        $order = DB::table('item_orders')->where('id', $orderId)->lockForUpdate()->first();
        if (in_array($order->status, ['completed', 'refunded'])) return;
        if (!$delivered) self::require(!DB::table('item_order_items')->where('order_id', $orderId)->where('delivered', '>', 0)->exists(), 'Đơn đã giao một phần; không thể hoàn toàn bộ tiền bằng thao tác chưa giao.');
        // Only called after confirmed worker result or explicit administrator reconciliation.
        foreach (DB::table('item_inventory_reservations')->where('order_id', $orderId)->where('status', 'held')->get() as $r) {
            DB::table('nro_inventory_items')->where('id', $r->inventory_item_id)->decrement('reserved', $r->quantity);
        }
        DB::table('item_inventory_reservations')->where('order_id', $orderId)->update(['status' => $delivered ? 'consumed' : 'released', 'updated_at' => now()]);
        $user = User::whereKey($delivered ? $order->seller_id : $order->buyer_id)->lockForUpdate()->firstOrFail();
        self::require((int) $user->balance <= TransactionService::MAX_BALANCE - $order->price, 'Số dư vượt giới hạn; cần đối soát.');
        $before = (int) $user->balance; $user->increment('balance', $order->price);
        TransactionService::log(userId: $user->id, type: $delivered ? 'sell_nro_items' : 'refund_nro_items', amount: $order->price,
            description: ($delivered ? 'Bán' : 'Hoàn tiền').' gói đồ #'.$orderId, related: 'nro_item_order', relatedId: $orderId,
            oldBalance: $before, newBalance: $before + $order->price, idempotencyKey: "nro-order:$orderId:settle");
        DB::table('item_orders')->where('id', $orderId)->update(['status' => $delivered ? 'completed' : 'refunded', 'updated_at' => now()]);
        if ($delivered) DB::table('item_order_items')->where('order_id', $orderId)->update(['delivered' => DB::raw('quantity')]);
        // Force a fresh inventory before the next sale after manual settlement.
        NroAccount::whereKey($order->account_id)->update(['last_synced_at' => null]);
    }
}
