<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NroAccount;
use App\Services\NroSnapshotService;
use App\Services\NroShopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NroWorkerController extends Controller
{
    public function accounts()
    {
        return response()->json(['data' => NroAccount::whereNotNull('usage_type')->where('status', 'active')->orderBy('id')->limit(500)
            ->get(['id','character_name','usage_type','server_id','last_synced_at'])])->header('Cache-Control', 'no-store');
    }
    public function claim(Request $r, NroShopService $shop)
    {
        $r->validate(['protocolVersion' => 'required|integer|in:2,3', 'types' => 'required|array|min:1|max:2', 'types.*' => 'required|in:snapshot,delivery']);
        DB::table('nro_worker_keys')->where('id', $r->attributes->get('nro_worker_key_id'))->update(['accepts_delivery' => in_array('delivery', $r->input('types'))]);
        return DB::transaction(function () use ($r, $shop) {
            // Never automatically replay an expired job: a disconnected worker may have delivered already.
            $expired = DB::table('nro_worker_jobs')->where('status', 'processing')->where('lease_until', '<', now())->get();
            foreach ($expired as $old) {
                NroAccount::whereKey($old->account_id)->lockForUpdate()->first();
                $old = DB::table('nro_worker_jobs')->where('id', $old->id)->lockForUpdate()->first();
                if (!$old || $old->status !== 'processing' || $old->lease_until >= now()->toDateTimeString()) continue;
                if (app(\App\Services\NroReceivingService::class)->retryInterrupted($old)) continue;
                $changed = DB::table('nro_worker_jobs')->where('id', $old->id)->where('status', 'processing')->where('lease_until', '<', now())->update(['status' => 'review', 'updated_at' => now()]);
                if ($changed && $old->order_id) DB::table('item_orders')->where('id', $old->order_id)->whereNotIn('status', ['completed', 'refunded'])->update(['status' => 'review', 'delivery_message' => 'Tool mất kết nối; đang chờ đối soát.']);
                if ($changed && $old->type === 'snapshot') NroAccount::whereKey($old->account_id)->where('auto_publish', true)->update(['publish_status' => 'scan_failed', 'publish_error' => 'Tool mất kết nối khi lấy dữ liệu. Kiểm tra công việc rồi lấy lại snapshot.']);
                if ($changed) app(\App\Services\NroReceivingService::class)->finish($old, 'review');
            }
            // Refresh unconfirmed stock only; a complete snapshot does not expire with age.
            if (in_array('snapshot', $r->input('types'))) {
                $stale = NroAccount::where('usage_type', 'warehouse')->where('status', 'active')
                    ->whereNull('last_synced_at')
                    ->whereNotIn('id', DB::table('nro_worker_jobs')->select('account_id')->whereIn('status', ['queued', 'processing', 'review']))
                    ->whereNotIn('id', DB::table('nro_worker_jobs')->select('account_id')->where('updated_at', '>', now()->subMinutes(2)))
                    ->orderBy('id')->limit(10)->get();
                foreach ($stale as $a) {
                    $current = NroAccount::whereKey($a->id)->lockForUpdate()->first();
                    if (!$current || $current->last_synced_at !== null || $current->status !== 'active') continue;
                    if (!DB::table('nro_worker_jobs')->where('account_id', $a->id)->whereIn('status', ['queued', 'processing', 'review'])->exists()
                        && !DB::table('nro_worker_jobs')->where('account_id', $a->id)->where('updated_at', '>', now()->subMinutes(2))->exists())
                        DB::table('nro_worker_jobs')->insert(['account_id' => $a->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $candidates = DB::table('nro_worker_jobs')->where('status', 'queued')->whereIn('type', $r->input('types'))->orderByRaw("CASE WHEN type = 'delivery' THEN 0 ELSE 1 END")->orderBy('id')->limit(50)->get();
            foreach ($candidates as $candidate) {
                $account = NroAccount::whereKey($candidate->account_id)->lockForUpdate()->first();
                if (!$account || $account->status !== 'active' || !$account->game_password || !$account->server_game_id) continue;
                $endpoint = DB::table('server_game_login')->where('id', $account->server_game_id)->first();
                if (!$endpoint) continue;
                if (DB::table('nro_worker_jobs')->where('account_id', $account->id)->whereIn('status', ['processing', 'review'])->exists()) continue;
                $job = DB::table('nro_worker_jobs')->where('id', $candidate->id)->lockForUpdate()->first();
                if ($job->status !== 'queued') continue;
                $session = $job->delivery_session_id ? DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->first() : null;
                // Legacy delivery jobs must be reconciled, never executed under the new protocol.
                if ($job->type === 'delivery' && !$session) continue;
                if ($job->type === 'delivery' && $account->delivery_zone_mode === 'auto' && $r->integer('protocolVersion') < 3) continue;
                $token = (string) Str::uuid();
                DB::table('nro_worker_jobs')->where('id', $job->id)->update(['status' => 'processing', 'worker_key_id' => $r->attributes->get('nro_worker_key_id'), 'lease_token' => $token, 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);
                if ($job->order_id) DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'processing', 'delivery_message' => 'Bot đang chuẩn bị đồ.']);
                if ($session) DB::table('nro_delivery_sessions')->where('id', $session->id)->update(['status' => 'preparing', 'updated_at' => now()]);
                return response()->json(['data' => ['id' => $job->id, 'type' => $job->type, 'leaseToken' => $token,
                    'account' => ['id' => $account->id, 'username' => $account->account_name, 'password' => $account->game_password, 'serverIndex' => $account->server_index ?? 0,
                        'host' => $endpoint->ip, 'port' => (int) $endpoint->port, 'serverId' => $account->server_id,
                        'deliveryMap' => $account->delivery_map, 'deliveryZone' => $account->delivery_zone, 'deliveryZoneMode' => $account->delivery_zone_mode],
                    'receiving' => $session ? ['id' => $session->id, 'mode' => $session->mode, 'recipientName' => $session->recipient_name,
                        'receiver' => $session->receiver_credentials ? json_decode(\Illuminate\Support\Facades\Crypt::decryptString($session->receiver_credentials), true) : null] : null,
                    'order' => $job->order_id ? $shop->order($job->order_id) : null]])->header('Cache-Control', 'no-store');
            }
            return response()->json(['data' => null]);
        }, 3);
    }

    private function job(Request $r, int $id): object
    {
        $job = DB::table('nro_worker_jobs')->where('id', $id)->lockForUpdate()->first();
        abort_unless($job && $job->worker_key_id == $r->attributes->get('nro_worker_key_id') && hash_equals($job->lease_token ?? '', (string) $r->input('leaseToken')), 403);
        return $job;
    }

    public function heartbeat(Request $r, int $id)
    {
        $r->validate(['leaseToken' => 'required|uuid', 'message' => 'nullable|string|max:250']);
        return DB::transaction(function () use ($r, $id) {
            $job = $this->job($r, $id);
            abort_unless($job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
            DB::table('nro_worker_jobs')->where('id', $id)->update(['lease_until' => now()->addMinutes(3), 'updated_at' => now()]);
            if ($job->order_id && $r->filled('message')) DB::table('item_orders')->where('id', $job->order_id)->update(['delivery_message' => $r->input('message'), 'updated_at' => now()]);
            return response()->json(['ok' => true]);
        });
    }

    public function complete(Request $r, int $id, NroSnapshotService $snapshots, NroShopService $shop)
    {
        $r->validate(['leaseToken' => 'required|uuid', 'outcome' => 'required|in:success,review,failed,expired,missing_items,login_failed',
            'message' => 'nullable|string|max:250', 'payload' => 'nullable|array',
            'loginFailureKind' => 'nullable|string|max:50', 'retryable' => 'nullable|boolean',
            'loginAccountRole' => 'nullable|in:sender,receiver']);
        abort_if(strlen($r->getContent()) > 4 * 1024 * 1024, 413);
        return DB::transaction(function () use ($r, $id, $snapshots, $shop) {
            // Account lock serializes stock ingestion with purchases and listing changes.
            $accountId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id'); abort_unless($accountId, 404);
            $account = NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job = $this->job($r, $id);
            if (in_array($job->status, ['completed', 'failed', 'expired'])) return response()->json(['ok' => true]);
            abort_unless(in_array($job->status, ['processing', 'review']), 409);
            if ($r->input('outcome') === 'missing_items') {
                // A journal replay after a long outage must not refund from stale stock or block the worker forever.
                if ($job->lease_until < now()->toDateTimeString() && app(\App\Services\NroReceivingService::class)->retryInterrupted($job)) {
                    return response()->json(['ok' => true, 'retrySafe' => true]);
                }
                abort_unless($job->type === 'delivery' && $job->order_id && $job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
                $session = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
                abort_unless($session && $session->trade_in_flight !== null && !(bool) $session->trade_in_flight, 409);
                abort_if(DB::table('item_order_items')->where('order_id', $job->order_id)->where('delivered', '>', 0)->exists(), 409);
                $payload = $r->input('payload');
                NroShopService::require(is_array($payload) && ($payload['completeness']['bag'] ?? null) === true && ($payload['completeness']['chest'] ?? null) === true && ($payload['completeness']['equipped'] ?? null) === true, 'Cần snapshot đầy đủ để xác nhận thiếu đồ.');
                $snapshot = $snapshots->ingest($account, $payload);
                NroShopService::require($snapshot->captured_at->between(now()->subMinutes(5), now()->addMinutes(5)), 'Snapshot xác nhận tồn kho đã quá hạn.');
                $items = array_merge($snapshot->data_json['bag'], $snapshot->data_json['chest'], $snapshot->data_json['equipped']);
                $missing = DB::table('item_order_items')->where('order_id', $job->order_id)->get()->contains(function ($line) use ($items) {
                    $expected = NroSnapshotService::identity(json_decode($line->item_json, true));
                    $count = array_sum(array_map(fn ($item) => NroSnapshotService::identity($item) === $expected ? $item['quantity'] : 0, $items));
                    return $count < $line->quantity;
                });
                NroShopService::require($missing, 'Snapshot vẫn đủ đúng vật phẩm; không được hoàn tiền vì thiếu đồ.');
                $shop->settle($job->order_id, false);
                DB::table('item_orders')->where('id', $job->order_id)->update(['delivery_message' => 'Kho thiếu đúng vật phẩm trước khi giao. Toàn bộ tiền đơn đã được hoàn vào số dư.']);
                app(\App\Services\NroReceivingService::class)->finish($job, 'refunded');
                DB::table('nro_worker_jobs')->where('id', $id)->update(['status' => 'completed', 'result_json' => json_encode(['reason' => 'missing_items', 'snapshotId' => $snapshot->id, 'refunded' => true]), 'updated_at' => now()]);
                return response()->json(['ok' => true, 'refunded' => true]);
            }
            $success = $r->input('outcome') === 'success';
            $expired = $r->input('outcome') === 'expired';
            $permanentSenderLoginFailure = $r->input('loginAccountRole') === 'sender'
                && $r->boolean('retryable', true) === false && $r->filled('loginFailureKind');
            if ($permanentSenderLoginFailure) $account->update([
                'publish_status' => 'login_blocked',
                'publish_error' => $r->input('message') ?: 'Acc kho bị chặn đăng nhập. Hãy sửa mật khẩu trước khi chạy lại.',
            ]);
            if (!$success && !$expired && app(\App\Services\NroReceivingService::class)->retryInterrupted($job, $r->input('message'))) return response()->json(['ok' => true, 'retrySafe' => true]);
            if ($expired) {
                $session = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
                abort_unless($session && $session->status === 'ready' && !$session->trade_in_flight && $session->expires_at <= now()->toDateTimeString(), 409);
            }
            $snapshot = null;
            if ($success) {
                abort_unless(is_array($r->input('payload')), 422);
                $snapshot = $snapshots->ingest($account, $r->input('payload'));
            }
            if ($job->order_id) {
                if ($success) {
                    abort_if(DB::table('item_order_items')->where('order_id', $job->order_id)->whereColumn('delivered', '<', 'quantity')->exists(), 409);
                    // Worker only reports success after every selected item is confirmed removed in trade.
                    $shop->settle($job->order_id, true);
                    $account->update(['last_synced_at' => ($snapshot->completeness_json['bag'] && $snapshot->completeness_json['chest']) ? $snapshot->captured_at : null]);
                } elseif ($expired) {
                    DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'awaiting_receipt', 'delivery_message' => 'Hết giờ nhận. Đồ vẫn được giữ; bạn có thể bấm nhận lại.', 'updated_at' => now()]);
                } else {
                    DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'review', 'delivery_message' => 'Phiên giao bị gián đoạn khi kết quả chưa được xác nhận. Shop đang đối soát; bạn không cần mua hoặc thanh toán lại.', 'updated_at' => now()]);
                }
            }
            $status = $success ? 'completed' : ($expired ? 'expired' : ($job->order_id || $r->input('outcome') === 'review' ? 'review' : 'failed'));
            app(\App\Services\NroReceivingService::class)->finish($job, $status);
            DB::table('nro_worker_jobs')->where('id', $id)->update(['status' => $status, 'result_json' => json_encode(['message' => $r->input('message'), 'snapshotId' => $snapshot?->id]), 'updated_at' => now()]);
            if ($job->type === 'snapshot' && $account->auto_publish) {
                if ($success) app(\App\Services\NroAutoPublishService::class)->publish($account->id);
                else $account->update(['publish_status' => 'scan_failed', 'publish_error' => $r->input('message') ?: 'Lấy dữ liệu chưa thành công. Kiểm tra tài khoản và công việc tool rồi lấy lại snapshot.']);
            }
            if ($permanentSenderLoginFailure) $account->update([
                'publish_status' => 'login_blocked',
                'publish_error' => $r->input('message') ?: 'Acc bị chặn đăng nhập. Hãy sửa mật khẩu trước khi chạy lại.',
            ]);
            return response()->json(['ok' => true, 'snapshotId' => $snapshot?->id]);
        }, 3);
    }

    public function progress(Request $r, int $id)
    {
        $r->validate(['leaseToken' => 'required|uuid', 'items' => 'required|array|min:1|max:20',
            'items.*.id' => 'required|integer|distinct', 'items.*.delivered' => 'required|integer|min:0']);
        return DB::transaction(function () use ($r, $id) {
            $accountId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id');
            NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job = $this->job($r, $id);
            abort_unless($job->type === 'delivery' && $job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
            $hasProgress = false;
            foreach ($r->input('items') as $line) {
                $item = DB::table('item_order_items')->where('id', $line['id'])->where('order_id', $job->order_id)->lockForUpdate()->first();
                abort_unless($item && $line['delivered'] >= $item->delivered && $line['delivered'] <= $item->quantity, 422);
                $delta = $line['delivered'] - $item->delivered;
                if ($delta > 0) {
                    $hasProgress = true;
                    $reservation = DB::table('item_inventory_reservations')->where('order_id', $job->order_id)->where('inventory_item_id', $item->inventory_item_id)->lockForUpdate()->first();
                    abort_unless($reservation && $reservation->status === 'held' && $reservation->quantity >= $delta, 409);
                    DB::table('item_inventory_reservations')->where('id', $reservation->id)->decrement('quantity', $delta);
                    DB::table('nro_inventory_items')->where('id', $item->inventory_item_id)->decrement('reserved', $delta);
                    // Stock is unavailable for sale until a fresh snapshot after the session.
                    NroAccount::whereKey($accountId)->update(['last_synced_at' => null]);
                }
                DB::table('item_order_items')->where('id', $item->id)->update(['delivered' => $line['delivered']]);
            }
            $allItemIds = DB::table('item_order_items')->where('order_id', $job->order_id)->pluck('id')->all();
            if ($hasProgress && !array_diff($allItemIds, array_column($r->input('items'), 'id'))) {
                DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->where('status', 'trading')->update(['status' => 'ready', 'trade_in_flight' => false, 'updated_at' => now()]);
            }
            return response()->json(['ok' => true]);
        });
    }

    public function ready(Request $r, int $id)
    {
        $v = $r->validate(['leaseToken' => 'required|uuid', 'characterId' => 'required|integer', 'name' => 'required|string|max:50',
            'mapId' => 'required|integer|min:0|max:10000', 'mapName' => 'nullable|string|max:100', 'zone' => 'required|integer|min:0|max:255',
            'x' => 'nullable|integer|min:-10000|max:10000', 'y' => 'nullable|integer|min:-10000|max:10000',
            'recipientName' => 'required|string|max:50']);
        return DB::transaction(function () use ($r, $id, $v) {
            $job = $this->job($r, $id);
            abort_unless($job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
            $s = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
            abort_unless($s && in_array($s->status, ['preparing','ready']), 409);
            if ($s->mode === 'manual') abort_unless($v['recipientName'] === $s->recipient_name, 422);
            $expires = $s->expires_at ? \Carbon\Carbon::parse($s->expires_at) : now()->addMinutes($s->wait_minutes);
            unset($v['leaseToken']);
            DB::table('nro_delivery_sessions')->where('id', $s->id)->update(['status' => 'ready', 'ready_at' => $s->ready_at ?? now(), 'expires_at' => $expires,
                'position_json' => json_encode($v), 'recipient_name' => $v['recipientName'], 'updated_at' => now()]);
            return response()->json(['expiresAt' => $expires->toIso8601String(), 'remainingSeconds' => max(0, now()->diffInSeconds($expires, false))]);
        });
    }

    public function beginRound(Request $r, int $id)
    {
        $r->validate(['leaseToken' => 'required|uuid']);
        return DB::transaction(function () use ($r, $id) {
            $job = $this->job($r, $id);
            abort_unless($job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
            $s = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
            abort_unless($s && $s->status === 'ready' && $s->expires_at > now()->toDateTimeString(), 409);
            DB::table('nro_delivery_sessions')->where('id', $s->id)->update(['status' => 'trading', 'trade_in_flight' => true, 'updated_at' => now()]);
            return response()->json(['ok' => true]);
        });
    }
}
