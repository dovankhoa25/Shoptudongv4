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
            ->get(['id','account_name','character_name','usage_type','server_id','last_synced_at','snapshot_failures','publish_error'])])->header('Cache-Control', 'no-store');
    }
    public function claim(Request $r, NroShopService $shop)
    {
        $r->validate(['protocolVersion' => 'required|integer|in:2,3,4', 'workerInstance' => 'required_if:protocolVersion,4|nullable|uuid', 'activeAccountIds' => 'sometimes|array', 'activeAccountIds.*' => 'integer|min:1', 'allowNewAccount' => 'sometimes|boolean', 'types' => 'required|array|min:1|max:2', 'types.*' => 'required|in:snapshot,delivery']);
        DB::table('nro_worker_keys')->where('id', $r->attributes->get('nro_worker_key_id'))->update(['accepts_delivery' => in_array('delivery', $r->input('types'))]);
        $r->attributes->set('nro_changed_accounts', app(\App\Services\NroClaimMaintenance::class)->run(in_array('snapshot',$r->input('types'))));
        return DB::transaction(function () use ($r, $shop) {
            $candidateBase = DB::table('nro_worker_jobs')->where('status', 'queued')->whereIn('type', $r->input('types'))->when($r->integer('protocolVersion') >= 4 && !$r->boolean('allowNewAccount', true), fn ($q) => $q->whereIn('account_id', $r->input('activeAccountIds', [])));
            // Keyset pages remain correct when rejected jobs are finalized while scanning.
            $candidates=(function() use($candidateBase) {
                foreach(['audit','delivery','snapshot'] as $kind) {
                    $query=clone $candidateBase;
                    if($kind==='audit') $query->whereNotNull('audit_order_id');
                    else $query->whereNull('audit_order_id')->where('type',$kind);
                    yield from $query->lazyById(100);
                }
            })();
            $busyAccounts=[];
            foreach ($candidates as $candidate) {
                if(isset($busyAccounts[$candidate->account_id])) continue;
                $account = NroAccount::whereKey($candidate->account_id)->lockForUpdate()->first();
                $job = DB::table('nro_worker_jobs')->where('id', $candidate->id)->lockForUpdate()->first();
                if (!$job || $job->status !== 'queued') continue;
                if (DB::table('nro_worker_jobs')->where('account_id',$job->account_id)->where('id','!=',$job->id)->whereNotNull('recovery_json')->whereIn('status',['queued','processing'])->exists()) continue;
                if ($job->recovery_json && DB::table('nro_worker_jobs')->where('account_id',$job->account_id)->where('id','!=',$job->id)->where('status','processing')->exists()) continue;
                if ($job->order_id && DB::table('item_orders')->where('id',$job->order_id)->where('failure_code','login_wait')->where('login_retry_at','>',now())->exists()) continue;
                if (!$job->audit_order_id && DB::table('nro_worker_jobs')->where('account_id',$job->account_id)->whereNotNull('audit_order_id')->whereIn('status',['queued','processing'])->exists()) continue;
                $blockedReason = !$account ? 'Acc của job không còn tồn tại.'
                    : ($account->status !== 'active' ? 'Acc đã ngừng hoạt động.'
                    : ($account->publish_status === 'login_blocked' ? ($account->publish_error ?: 'Acc đang bị chặn đăng nhập.')
                    : (!$account->game_password ? 'Acc chưa có mật khẩu.'
                    : (!$account->server_game_id ? 'Acc chưa cấu hình server đăng nhập.' : null))));
                if ($blockedReason) {
                    if (!app(\App\Services\NroReceivingService::class)->retryInterrupted($job, $blockedReason)) {
                        DB::table('nro_worker_jobs')->where('id', $job->id)->update(['status' => 'failed', 'result_json' => json_encode(['message' => $blockedReason]), 'updated_at' => now()]);
                        if ($account?->auto_publish) $account->update(['publish_status' => $account->publish_status === 'login_blocked' ? 'login_blocked' : 'scan_failed', 'publish_error' => $blockedReason]);
                    }
                    continue;
                }
                $endpoint = DB::table('server_game_login')->where('id', $account->server_game_id)->first();
                if (!$endpoint) {
                    $reason = 'Server đăng nhập của acc không còn tồn tại.';
                    if (!app(\App\Services\NroReceivingService::class)->retryInterrupted($job, $reason)) DB::table('nro_worker_jobs')->where('id', $job->id)->update(['status' => 'failed', 'result_json' => json_encode(['message' => $reason]), 'updated_at' => now()]);
                    continue;
                }
                $active = DB::table('nro_worker_jobs')->where('account_id', $account->id)->where(function ($q) {
                    $q->whereIn('status', ['processing', 'review'])->orWhere(fn ($l) => $l->whereNotNull('worker_instance')->where('lease_until', '>=', now()));
                })->get();
                // Only deliveries in the SAME running worker process may share a game session.
                // Legacy workers, snapshot jobs and uncertain trades retain exclusive ownership.
                if ($active->contains(function ($running) use ($r, $job) {
                    // Explicit admin inspection only: expired review stays unresolved, but a read-only snapshot may run.
                    if ($job->type === 'snapshot' && $job->audit_order_id && $running->status === 'review' && (!$running->lease_until || $running->lease_until < now()->toDateTimeString())) return false;
                    return $r->integer('protocolVersion') < 4 || $job->type !== 'delivery'
                        || $running->type !== 'delivery' || $running->status === 'review'
                        || $running->worker_key_id != $r->attributes->get('nro_worker_key_id')
                        || !$running->worker_instance || $running->worker_instance !== $r->input('workerInstance');
                })) { $busyAccounts[$account->id]=true;continue; }
                $session = $job->delivery_session_id ? DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->first() : null;
                // Legacy delivery jobs must be reconciled, never executed under the new protocol.
                if ($job->type === 'delivery' && !$session) {
                    DB::table('nro_worker_jobs')->where('id', $job->id)->update(['status' => 'review', 'result_json' => json_encode(['message' => 'Job giao đồ cũ thiếu phiên nhận; cần đối soát.']), 'updated_at' => now()]);
                    if ($job->order_id) DB::table('item_orders')->where('id', $job->order_id)->whereNotIn('status', ['completed', 'refunded'])->update(['status' => 'review', 'delivery_message' => 'Job giao đồ cũ thiếu phiên nhận; shop đang đối soát.', 'updated_at' => now()]);
                    continue;
                }
                if ($job->type === 'delivery' && $account->delivery_zone_mode === 'auto' && $r->integer('protocolVersion') < 3) continue;
                $token = (string) Str::uuid();
                DB::table('nro_worker_jobs')->where('id', $job->id)->update(['status' => 'processing', 'worker_instance' => $r->input('workerInstance'), 'worker_key_id' => $r->attributes->get('nro_worker_key_id'), 'lease_token' => $token, 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);
                if ($job->order_id) DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'processing', 'delivery_message' => 'Bot đang chuẩn bị đồ.']);
                if ($session) DB::table('nro_delivery_sessions')->where('id', $session->id)->update(['status' => 'preparing', 'updated_at' => now()]);
                return response()->json(['data' => ['id' => $job->id, 'type' => $job->type, 'leaseToken' => $token,
                    'recovery' => $job->recovery_json ? json_decode($job->recovery_json,true) : null,
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

    private function job(Request $r, int $id, bool $allowKeyRotation = false): object
    {
        $job = DB::table('nro_worker_jobs')->where('id', $id)->lockForUpdate()->first();
        abort_unless($job && ($allowKeyRotation || $job->worker_key_id == $r->attributes->get('nro_worker_key_id'))
            && hash_equals($job->lease_token ?? '', (string) $r->input('leaseToken')), 403);
        return $job;
    }

    public function heartbeat(Request $r, int $id)
    {
        $v=$r->validate(['leaseToken'=>'required|uuid','message'=>'nullable|string|max:250','loginRetryAt'=>'nullable|date','loginWaiting'=>'nullable|boolean']);
        $result=app(\App\Services\NroHeartbeat::class)->renew((int)$r->attributes->get('nro_worker_key_id'),['id'=>$id]+$v);
        $this->heartbeatChanges($r,[$result]);
        return response()->json(\Illuminate\Support\Arr::except($result,['_order','_account']));
    }
    public function heartbeatBatch(Request $r)
    {
        $v=$r->validate(['workerInstance'=>'required|uuid','jobs'=>'required|array|min:1|max:200','jobs.*.id'=>'required|integer|min:1|distinct',
            'jobs.*.leaseToken'=>'required|uuid','jobs.*.message'=>'nullable|string|max:250','jobs.*.loginRetryAt'=>'nullable|date','jobs.*.loginWaiting'=>'nullable|boolean']);
        $results=[];$service=app(\App\Services\NroHeartbeat::class);
        foreach($v['jobs'] as $job) {
            try { $results[]=$service->renew((int)$r->attributes->get('nro_worker_key_id'),$job,$v['workerInstance']); }
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $results[]=['id'=>$job['id'],'ok'=>false,'status'=>$e->getStatusCode()]; }
        }
        $this->heartbeatChanges($r,$results);
        return response()->json(['results'=>array_map(fn($row)=>\Illuminate\Support\Arr::except($row,['_order','_account']),$results)]);
    }
    private function heartbeatChanges(Request $r,array $results): void
    {
        $orders=array_values(array_filter(array_column($results,'_order')));
        $accounts=array_values(array_unique(array_filter(array_column($results,'_account'))));
        $r->attributes->set('nro_changed_order_ids',$orders);
        $r->attributes->set('nro_changed_accounts',$accounts);
        $r->attributes->set('nro_delivery_message_changed',(bool)($orders || $accounts));
    }

    private function lateResult(Request $r, object $job, string $kind): void
    {
        $payload=$r->only(['outcome','message','payload','items','loginFailureKind','retryable','loginAccountRole']);
        // Store only reconciliation evidence, never arbitrary fields from a worker snapshot.
        if (isset($payload['items'])) $payload['items']=array_map(fn($i)=>\Illuminate\Support\Arr::only($i,['id','delivered']),$payload['items']);
        if (isset($payload['payload'])) {
            $source=$payload['payload']['snapshot'] ?? [];
            $evidence=['capturedAt'=>$source['capturedAt'] ?? null];
            foreach (['bag','chest','equipped'] as $location) $evidence[$location]=array_map(function($i) {
                return ['templateId'=>$i['templateId'] ?? null,'quantity'=>$i['quantity'] ?? null,'slot'=>$i['slot'] ?? null,
                    'options'=>array_map(fn($o)=>\Illuminate\Support\Arr::only($o,['optionId','param']),$i['options'] ?? [])];
            }, $source[$location] ?? []);
            $payload['payload']=$evidence;
        }
        $payload['leaseVerified']=$job->lease_token !== null;
        $json=json_encode($payload, JSON_UNESCAPED_UNICODE);
        DB::table('nro_late_results')->insertOrIgnore(['job_id'=>$job->id,'kind'=>$kind,'digest'=>hash('sha256',$job->id.':'.$kind.':'.$json),'payload_json'=>$json,'created_at'=>now()]);
    }

    public function complete(Request $r, int $id, NroSnapshotService $snapshots, NroShopService $shop)
    {
        $r->validate(['leaseToken' => 'required|uuid', 'outcome' => 'required|in:success,review,interrupted,reconnect_check,failed,expired,missing_items,login_failed,trade_paused,trade_recovered',
            'message' => 'nullable|string|max:250', 'payload' => 'nullable|array',
            'loginFailureKind' => 'nullable|string|max:50', 'retryable' => 'nullable|boolean', 'loginRetryAt' => 'nullable|date',
            'loginAccountRole' => 'nullable|in:sender,receiver', 'tradeEvidence'=>'nullable|in:server_cancelled,server_success,inventory']);
        abort_if(strlen($r->getContent()) > 4 * 1024 * 1024, 413);
        return DB::transaction(function () use ($r, $id, $snapshots, $shop) {
            // Account lock serializes stock ingestion with purchases and listing changes.
            $accountId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id'); abort_unless($accountId, 404);
            $account = NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $terminal = DB::table('nro_worker_jobs')->where('id', $id)->lockForUpdate()->first();
            if ($terminal && in_array($terminal->status, ['completed', 'failed', 'expired'])) {
                if ($terminal->lease_token !== null) $this->job($r, $id, true);
                $this->lateResult($r, $terminal, 'complete');
                return response()->json(['ok' => true, 'alreadyFinalized' => true]);
            }
            // A restarted tool may use a newly issued key; the unguessable lease token still binds the journal to this job.
            $job = $this->job($r, $id, true);
            abort_unless(in_array($job->status, ['processing', 'review']), 409);
            if ($r->input('outcome') === 'reconnect_check') {
                $r->validate(['recovery'=>'required|array|min:1|max:200','recovery.*.id'=>'required|integer|distinct',
                    'recovery.*.before'=>'required|integer|min:0','recovery.*.offered'=>'required|integer|min:0', 'recovery.*.delivered'=>'required|integer|min:0']);
                abort_unless($job->type==='delivery' && $job->delivery_session_id,422);
                $session=DB::table('nro_delivery_sessions')->where('id',$job->delivery_session_id)->lockForUpdate()->first();
                $lines=DB::table('item_order_items')->where('order_id',$job->order_id)->get()->keyBy('id');
                abort_unless($session && $lines->count()===count($r->input('recovery')),422);
                foreach($r->input('recovery') as $line) {
                    $item=$lines->get($line['id']);
                    abort_unless($item && $line['delivered'] <= $item->delivered && $line['offered'] <= $item->quantity-$line['delivered'] && $line['before'] >= $line['offered'],422);
                }
                if(!$session->trade_in_flight && app(\App\Services\NroDeliveryLifecycle::class)->recover($job)) return response()->json(['ok'=>true,'recovered'=>true]);
                DB::table('nro_worker_jobs')->where('id',$id)->update(['status'=>'failed','result_json'=>json_encode(['reason'=>'automatic_reconnect_check']),'updated_at'=>now()]);
                DB::table('nro_delivery_sessions')->where('id',$session->id)->update(['status'=>'queued','trade_in_flight'=>false,'position_json'=>null,'updated_at'=>now()]);
                DB::table('nro_worker_jobs')->insert(['account_id'=>$accountId,'order_id'=>$job->order_id,'delivery_session_id'=>$session->id,'type'=>'delivery','status'=>'queued',
                    'recovery_json'=>json_encode($r->input('recovery')),'created_at'=>now(),'updated_at'=>now()]);
                DB::table('item_orders')->where('id',$job->order_id)->whereNotIn('status',['completed','refunded'])->update(['status'=>'queued','delivery_message'=>'Bot đang đăng nhập lại và kiểm tra lượt giao bị gián đoạn.','updated_at'=>now()]);
                return response()->json(['ok'=>true,'recovered'=>true]);
            }
            if ($r->input('outcome') === 'missing_items') {
                // A journal replay after a long outage must not refund from stale stock or block the worker forever.
                if ($job->lease_until < now()->toDateTimeString() && app(\App\Services\NroReceivingService::class)->retryInterrupted($job)) {
                    return response()->json(['ok' => true, 'retrySafe' => true]);
                }
                abort_unless($job->type === 'delivery' && $job->order_id && $job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
                $session = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
                abort_unless($session && $session->trade_in_flight !== null && !(bool) $session->trade_in_flight, 409);
                $payload = $r->input('payload');
                NroShopService::require(is_array($payload) && ($payload['completeness']['bag'] ?? null) === true && ($payload['completeness']['chest'] ?? null) === true && ($payload['completeness']['equipped'] ?? null) === true, 'Cần snapshot đầy đủ để xác nhận thiếu đồ.');
                $snapshot = $snapshots->ingest($account, $payload);
                NroShopService::require($snapshot->captured_at->between(now()->subMinutes(5), now()->addMinutes(5)), 'Snapshot xác nhận tồn kho đã quá hạn.');
                $items = array_merge($snapshot->data_json['bag'], $snapshot->data_json['chest'], $snapshot->data_json['equipped']);
                $missing = DB::table('item_order_items')->where('order_id', $job->order_id)->get()->contains(function ($line) use ($items) {
                    $expected = NroSnapshotService::identity(json_decode($line->item_json, true));
                    $count = array_sum(array_map(fn ($item) => NroSnapshotService::identity($item) === $expected ? $item['quantity'] : 0, $items));
                    return $count < $line->quantity - $line->delivered;
                });
                NroShopService::require($missing, 'Snapshot vẫn đủ đúng vật phẩm; không được hoàn tiền vì thiếu đồ.');
                DB::table('item_orders')->where('id', $job->order_id)->update(['refund_requested'=>true, 'failure_code'=>'missing_items','public_failure'=>'Kho thiếu đúng vật phẩm. Chờ shop bổ sung hoặc hủy hoàn tiền nếu chưa nhận món nào.', 'status'=>'awaiting_receipt',
                    'delivery_message'=>(DB::table('item_order_items')->where('order_id',$job->order_id)->where('delivered','>',0)->exists() ? 'Kho thiếu phần còn lại. Shop cần bổ sung để bạn nhận đủ; đơn đã nhận một phần không hoàn tiền.' : 'Kho thiếu đồ. Bạn có thể chờ shop bổ sung hoặc yêu cầu hủy hoàn tiền.'), 'updated_at'=>now()]);
                app(\App\Services\NroReceivingService::class)->finish($job, 'failed');
                DB::table('nro_worker_jobs')->where('id', $id)->update(['status'=>'failed', 'result_json'=>json_encode(['reason'=>'missing_items','snapshotId'=>$snapshot->id,'refundRequested'=>true]),'updated_at'=>now()]);
                return response()->json(['ok'=>true,'refundRequested'=>true,'refunded'=>false]);
            }
            if (in_array($r->input('outcome'), ['trade_paused', 'trade_recovered'])) {
                abort_unless($job->type === 'delivery', 409);
                $session = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
                abort_unless($session && in_array($session->status, ['trading', 'review']) && $session->trade_in_flight, 409);
                // A durable zero-transfer result remains valid after lease expiry. Never ingest its old stock.
                $payload = $r->input('payload');
                abort_unless($job->worker_instance && (is_array($payload) || $r->input('tradeEvidence') === 'server_cancelled'), 422);
                NroShopService::require($r->input('tradeEvidence') === 'server_cancelled' || (($payload['completeness']['bag'] ?? null) === true
                    && ($payload['completeness']['chest'] ?? null) === true && ($payload['completeness']['equipped'] ?? null) === true),
                    'Cần dữ liệu đầy đủ để xác nhận giao dịch hủy chưa chuyển đồ.');
                $otherRunning = DB::table('nro_worker_jobs')->where('account_id', $accountId)->where('id', '!=', $id)->where('status', 'processing')->exists();
                if (is_array($payload) && $job->status === 'processing' && $job->lease_until >= now()->toDateTimeString() && !$otherRunning) $snapshots->ingest($account, $payload);
                else $account->update(['last_synced_at' => null]);
                if ($r->input('outcome') === 'trade_recovered') {
                    DB::table('nro_delivery_sessions')->where('id', $session->id)->update(['trade_in_flight'=>false, 'status'=>'ready', 'updated_at'=>now()]);
                    DB::table('item_orders')->where('id',$job->order_id)->whereNotIn('status',['completed','refunded'])->update(['failure_code'=>null,'public_failure'=>null,'login_retry_at'=>null]);
                    if (app(\App\Services\NroDeliveryLifecycle::class)->recover($job)) return response()->json(['ok'=>true,'recovered'=>true,'retrySafe'=>true]);
                    abort(409, 'Không thể khôi phục phiên nhận.');
                }
                DB::table('nro_delivery_sessions')->where('id', $session->id)->update([
                    'status' => 'suspended', 'trade_in_flight' => false, 'retry_at' => now()->addMinutes(5),
                    'receiver_credentials' => null, 'receiver_lock' => null, 'updated_at' => now(),
                ]);
                DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'awaiting_receipt',
                    'delivery_message' => $r->input('message') ?: 'Phiên nhận tạm dừng vì quá 20 giây. Thử lại sau 5 phút; đồ vẫn được giữ.', 'updated_at' => now()]);
                DB::table('nro_worker_jobs')->where('id', $id)->update(['status' => 'failed',
                    'result_json' => json_encode(['reason' => 'trade_timeout', 'retrySafe' => true]), 'updated_at' => now()]);
                return response()->json(['ok' => true]);
            }
            $success = $r->input('outcome') === 'success';
            $expired = $r->input('outcome') === 'expired';
            $permanentSenderLoginFailure = $r->input('loginAccountRole') === 'sender'
                && $r->boolean('retryable', true) === false && $r->filled('loginFailureKind');
            if ($permanentSenderLoginFailure) $account->update([
                'publish_status' => 'login_blocked',
                'publish_error' => $r->input('message') ?: 'Acc kho bị chặn đăng nhập. Hãy sửa mật khẩu trước khi chạy lại.',
            ]);
            if ($permanentSenderLoginFailure && $r->input('loginFailureKind') === 'BadCredentials') {
                $account->update(['login_sale_blocked'=>true]);
                DB::table('item_orders')->where('account_id',$account->id)->whereNotIn('status',['completed','refunded'])->update([
                    'failure_code'=>'login_failed','public_failure'=>'Acc kho sai thông tin đăng nhập. Shop cần cập nhật; bạn có thể yêu cầu hủy nếu chưa nhận đồ.',
                    'login_retry_at'=>null,'updated_at'=>now()]);
            }
            if ($job->order_id && $r->input('outcome') === 'login_failed' && $r->boolean('retryable')
                && in_array($r->input('loginFailureKind'), ['ServerWait','ServerRejected','LoginTimeout','ProxyConnectionFailed','ConnectionFailed','TransportLost','Stalled','BadCredentials'])) {
                $retryAt = $r->filled('loginRetryAt') ? \Carbon\Carbon::parse($r->input('loginRetryAt')) : now()->addSeconds(35);
                if ($retryAt->lt(now())) $retryAt = now()->addSeconds(35);
                DB::table('item_orders')->where('id',$job->order_id)->update(['failure_code'=>'login_wait',
                    'public_failure'=>'Game chưa cho đăng nhập. Hệ thống sẽ tự thử lại khi hết thời gian chờ.', 'login_retry_at'=>$retryAt,'updated_at'=>now()]);
                if (app(\App\Services\NroDeliveryLifecycle::class)->recover($job)) return response()->json(['ok'=>true,'recovered'=>true,'retrySafe'=>true]);
            }
            if($job->order_id && $r->input('outcome')==='login_failed') DB::table('item_orders')->where('id',$job->order_id)->update([
                'failure_code'=>'login_failed','public_failure'=>$r->input('loginAccountRole')==='receiver' ? 'Acc nhận chưa thể đăng nhập hoặc chưa sẵn sàng nhận đồ. Kiểm tra lại thông tin nhận.' : 'Acc kho chưa thể đăng nhập. Bạn có thể chờ shop xử lý hoặc hủy nếu chưa nhận món nào.',
                'login_retry_at'=>null,'updated_at'=>now()]);
            if($job->order_id && in_array($r->input('outcome'), ['review','interrupted']) && app(\App\Services\NroDeliveryLifecycle::class)->recover($job)) return response()->json(['ok'=>true,'recovered'=>true,'retrySafe'=>true]);
            if (!$success && !$expired && app(\App\Services\NroReceivingService::class)->retryInterrupted($job, $r->input('message'))) return response()->json(['ok' => true, 'retrySafe' => true]);
            if ($expired) {
                abort_if(($account->delivery_activity['pauseStartedAt'] ?? null) !== null, 409, 'Đồng hồ đang tạm dừng khi bot lấy đồ.');
                $session = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
                abort_unless($session && $session->status === 'ready' && !$session->trade_in_flight && $session->expires_at <= now()->toDateTimeString(), 409);
            }
            if ($job->type === 'snapshot' && !$success) \App\Services\NroSnapshotRetries::failed($account->id, $r->input('message') ?: 'Lấy dữ liệu thất bại.');
            $snapshot = null;
            if ($success) {
                abort_unless($job->type === 'delivery' || is_array($r->input('payload')), 422);
                if ($job->type !== 'delivery') $snapshot = $snapshots->ingest($account, $r->input('payload'));
                if ($job->type === 'snapshot' && !(($snapshot->completeness_json['bag'] ?? false) && ($snapshot->completeness_json['chest'] ?? false) && ($snapshot->completeness_json['equipped'] ?? false))) \App\Services\NroSnapshotRetries::failed($account->id, 'Dữ liệu túi/rương chưa đầy đủ.');
            }
            if ($job->order_id) {
                if ($success) {
                    abort_if(DB::table('item_order_items')->where('order_id', $job->order_id)->whereColumn('delivered', '<', 'quantity')->exists(), 409);
                    // Worker only reports success after every selected item is confirmed removed in trade.
                    $shop->settle($job->order_id, true);
                    $account->update(['last_synced_at' => ($snapshot && $job->lease_until >= now()->toDateTimeString() && $snapshot->completeness_json['bag'] && $snapshot->completeness_json['chest']) ? $snapshot->captured_at : null]);
                } elseif ($expired) {
                    DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'awaiting_receipt', 'delivery_message' => 'Hết giờ nhận. Đồ vẫn được giữ; bạn có thể bấm nhận lại.', 'updated_at' => now()]);
                } else {
                    DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'review', 'delivery_message' => 'Phiên giao bị gián đoạn khi kết quả chưa được xác nhận. Shop đang đối soát; bạn không cần mua hoặc thanh toán lại.', 'updated_at' => now()]);
                }
            }
            $status = $success ? 'completed' : ($expired ? 'expired' : ($job->order_id ? 'review' : 'failed'));
            app(\App\Services\NroReceivingService::class)->finish($job, $status);
            DB::table('nro_worker_jobs')->where('id', $id)->update(['status' => $status, 'result_json' => json_encode(['message' => $r->input('message'), 'snapshotId' => $snapshot?->id]), 'updated_at' => now()]);
            if ($job->type === 'snapshot' && $account->auto_publish) {
                if ($success) app(\App\Services\NroAutoPublishService::class)->publish($account->id);
                // Failure status and the retry limit are maintained by NroSnapshotRetries.
            }
            if ($permanentSenderLoginFailure) $account->update([
                'publish_status' => 'login_blocked',
                'publish_error' => $r->input('message') ?: 'Acc bị chặn đăng nhập. Hãy sửa mật khẩu trước khi chạy lại.',
            ]);
            return response()->json(['ok' => true, 'snapshotId' => $snapshot?->id]);
        }, 3);
    }

    public function resultIssue(Request $r, int $id) {
        $r->validate(['leaseToken'=>'required|uuid','httpStatus'=>'required|integer|min:400|max:599']);
        return DB::transaction(function() use($r,$id) {
            $accountId=DB::table('nro_worker_jobs')->where('id',$id)->value('account_id');
            NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job=$this->job($r,$id,true);
            if(in_array($job->status,['completed','failed','expired'])) return response()->json(['ok'=>true]);
            $message='Tool đã lưu kết quả nhưng API chưa chấp nhận. Shop đang kiểm tra dữ liệu; không giao lại hoặc hoàn tiền khi chưa xác nhận.';
            DB::table('nro_worker_jobs')->where('id',$id)->update(['status'=>$job->type==='delivery'?'review':'failed','result_json'=>json_encode(['reason'=>'result_rejected','httpStatus'=>$r->integer('httpStatus')]),'updated_at'=>now()]);
            if($job->order_id) DB::table('item_orders')->where('id',$job->order_id)->update(['status'=>'review','failure_code'=>'result_rejected','public_failure'=>$message,'delivery_message'=>$message,'updated_at'=>now()]);
            if($job->type==='snapshot') \App\Services\NroSnapshotRetries::failed($accountId,'API từ chối kết quả quét. Kiểm tra nhật ký rồi lấy dữ liệu thủ công.');
            app(\App\Services\NroReceivingService::class)->finish($job,$job->type==='delivery'?'review':'failed');
            return response()->json(['ok'=>true]);
        },3);
    }
    public function progress(Request $r, int $id, NroSnapshotService $snapshots)
    {
        $r->validate(['leaseToken' => 'required|uuid', 'items' => 'required|array|min:1|max:20',
            'items.*.id' => 'required|integer|distinct', 'items.*.delivered' => 'required|integer|min:0', 'payload' => 'nullable|array']);
        abort_if(strlen($r->getContent()) > 4 * 1024 * 1024, 413);
        return DB::transaction(function () use ($r, $id, $snapshots) {
            $accountId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id');
            NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job = $this->job($r, $id, true);
            if (in_array($job->status, ['completed','failed','expired'])) { $this->lateResult($r,$job,'progress'); return response()->json(['ok'=>true,'alreadyFinalized'=>true]); }
            abort_unless($job->type === 'delivery' && in_array($job->status, ['processing','review']), 409);
            $hasProgress = false;
            foreach ($r->input('items') as $line) {
                $item = DB::table('item_order_items')->where('id', $line['id'])->where('order_id', $job->order_id)->lockForUpdate()->first();
                abort_unless($item && $line['delivered'] >= $item->delivered && $line['delivered'] <= $item->quantity, 422);
                $delta = $line['delivered'] - $item->delivered;
                if ($delta > 0) {
                    $hasProgress = true;
                    DB::table('item_orders')->where('id',$job->order_id)->update(['cancel_requested'=>false]);
                    $reservation = DB::table('item_inventory_reservations')->where('order_id', $job->order_id)->where('inventory_item_id', $item->inventory_item_id)->lockForUpdate()->first();
                    abort_unless($reservation && $reservation->status === 'held' && $reservation->quantity >= $delta, 409);
                    DB::table('item_inventory_reservations')->where('id', $reservation->id)->decrement('quantity', $delta);
                    DB::table('nro_inventory_items')->where('id', $item->inventory_item_id)->decrement('reserved', $delta);
                    $stock=DB::table('nro_inventory_items')->where('id',$item->inventory_item_id)->lockForUpdate()->first();
                    DB::table('nro_inventory_items')->where('id',$item->inventory_item_id)->update(['quantity'=>max(0,(int)$stock->quantity-$delta)]);
                    // Stock is unavailable for sale until a fresh snapshot after the session.
                    NroAccount::whereKey($accountId)->update(['last_synced_at' => null]);
                }
                DB::table('item_order_items')->where('id', $item->id)->update(['delivered' => $line['delivered']]);
            }
            $allItemIds = DB::table('item_order_items')->where('order_id', $job->order_id)->pluck('id')->all();
            if ($hasProgress && !array_diff($allItemIds, array_column($r->input('items'), 'id'))) {
                DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->whereIn('status', ['trading','review'])->update(['status' => 'ready', 'trade_in_flight' => false, 'updated_at' => now()]);
            }
            // Delivery acknowledgement never depends on a successful inventory refresh.
            // The independent stock endpoint ingests a new snapshot after the receipt is durable.
            return response()->json(['ok' => true]);
        });
    }

    public function recoveryChecked(Request $r,int $id) {
        $r->validate(['leaseToken'=>'required|uuid']);
        return DB::transaction(function() use($r,$id) {
            $accountId=DB::table('nro_worker_jobs')->where('id',$id)->value('account_id');
            NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();$job=$this->job($r,$id);
            abort_unless($job->status==='processing' && $job->lease_until>=now()->toDateTimeString(),409);
            DB::table('nro_worker_jobs')->where('order_id',$job->order_id)->update(['recovery_json'=>null,'updated_at'=>now()]);
            return response()->json(['ok'=>true]);
        });
    }
    public function stock(Request $r, int $id, NroSnapshotService $snapshots)
    {
        $r->validate(['leaseToken'=>'required|uuid','workerInstance'=>'required|uuid','payload'=>'required|array']);
        return DB::transaction(function() use($r,$id,$snapshots) {
            $accountId=DB::table('nro_worker_jobs')->where('id',$id)->value('account_id');
            $account=NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job=$this->job($r,$id,true);
            abort_unless($job->worker_instance === $r->input('workerInstance') && $job->lease_until >= now()->toDateTimeString(),409);
            abort_if(DB::table('nro_worker_jobs')->where('account_id',$accountId)->where('worker_instance','!=',$job->worker_instance)->where('status','processing')->exists(),409);
            $snapshot=$snapshots->ingest($account,$r->input('payload'));
            return response()->json(['ok'=>true,'snapshotId'=>$snapshot->id]);
        });
    }

    public function ready(Request $r, int $id)
    {
        $v = $r->validate(['leaseToken' => 'required|uuid', 'characterId' => 'required|integer', 'name' => 'required|string|max:50',
            'mapId' => 'required|integer|min:0|max:10000', 'mapName' => 'nullable|string|max:100', 'zone' => 'required|integer|min:0|max:255',
            'x' => 'nullable|integer|min:-10000|max:10000', 'y' => 'nullable|integer|min:-10000|max:10000',
            'recipientName' => 'required|string|max:50']);
        return DB::transaction(function () use ($r, $id, $v) {
            $accountId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id');
            NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job = $this->job($r, $id);
            abort_unless($job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
            $s = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
            abort_unless($s && in_array($s->status, ['preparing','ready']), 409);
            if ($s->mode === 'manual') abort_unless($v['recipientName'] === $s->recipient_name, 422);
            if (DB::table('nro_delivery_sessions as s')->join('item_orders as o', 'o.id', '=', 's.order_id')
                ->where('o.account_id', $accountId)->where('s.id', '!=', $s->id)->where('s.recipient_name', $v['recipientName'])
                ->whereIn('s.status', ['ready', 'trading'])->exists()) {
                abort_unless($job->worker_instance && $s->mode === 'manual', 409, 'Nhân vật đang nhận một đơn khác.');
                return response()->json(['waitingForRecipient' => true], 202);
            }
            DB::table('item_orders')->where('id',$job->order_id)->where('failure_code','login_wait')->update(['failure_code'=>null,'public_failure'=>null,'login_retry_at'=>null]);
            $expires = $s->expires_at ? \Carbon\Carbon::parse($s->expires_at) : now()->addMinutes($s->wait_minutes);
            unset($v['leaseToken']);
            DB::table('nro_delivery_sessions')->where('id', $s->id)->update(['status' => 'ready', 'ready_at' => $s->ready_at ?? now(), 'expires_at' => $expires,
                'position_json' => json_encode($v), 'recipient_name' => $v['recipientName'], 'updated_at' => now()]);
            $pause = NroAccount::find($accountId)->delivery_activity['pauseStartedAt'] ?? null;
            $clock = $pause ? \Carbon\Carbon::parse($pause)->max(\Carbon\Carbon::parse($s->ready_at ?? now())) : now();
            return response()->json(['expiresAt' => $expires->toIso8601String(), 'remainingSeconds' => max(0, $clock->diffInSeconds($expires, false))]);
        });
    }

    public function beginRound(Request $r, int $id)
    {
        $r->validate(['leaseToken' => 'required|uuid']);
        return DB::transaction(function () use ($r, $id) {
            $accountId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id');
            NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job = $this->job($r, $id);
            abort_if(DB::table('nro_worker_jobs')->where('account_id',$accountId)->where('id','!=',$id)->whereNotNull('recovery_json')->whereIn('status',['queued','processing'])->exists(),409,'Bot đang khôi phục lượt giao trước.');
            abort_if(DB::table('nro_worker_jobs as j')->join('nro_delivery_sessions as s', 's.id', '=', 'j.delivery_session_id')
                ->where('j.account_id', $accountId)->where('j.id', '!=', $id)->where('s.trade_in_flight', true)
                ->whereIn('j.status', ['processing', 'review'])->exists(), 409, 'Bot đang giao một đơn khác.');
            abort_unless($job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
            abort_if((NroAccount::find($accountId)->delivery_activity['pauseStartedAt'] ?? null) !== null, 409, 'Bot đang lấy đồ hoặc di chuyển.');
            $s = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
            abort_if(DB::table('item_orders')->where('id',$job->order_id)->value('cancel_requested'),409,'Đơn đang yêu cầu hủy.');
            abort_unless($s && $s->status === 'ready' && $s->expires_at > now()->toDateTimeString(), 409);
            DB::table('nro_delivery_sessions')->where('id', $s->id)->update(['status' => 'trading', 'trade_in_flight' => true, 'trade_phase' => 'locking', 'phase_deadline' => now()->addSeconds(20), 'updated_at' => now()]);
            return response()->json(['ok' => true]);
        });
    }
    public function tradePhase(Request $r, int $id)
    {
        $r->validate(['leaseToken' => 'required|uuid', 'phase' => 'required|in:confirming']);
        return DB::transaction(function () use ($r, $id) {
            $job = $this->job($r, $id);
            abort_unless($job->status === 'processing' && $job->lease_until >= now()->toDateTimeString(), 409);
            $s = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
            abort_unless($s && $s->status === 'trading' && $s->trade_in_flight, 409);
            if ($s->trade_phase !== 'confirming') DB::table('nro_delivery_sessions')->where('id', $s->id)->update([
                'trade_phase' => 'confirming', 'phase_deadline' => now()->addSeconds(20), 'updated_at' => now(),
            ]);
            return response()->json(['ok' => true]);
        });
    }

    public function warehouseState(Request $r, int $id, \App\Services\NroWarehouseActivity $activity)
    {
        $v = $r->validate(['leaseToken' => 'required|uuid', 'phase' => 'required|in:home,collecting,travelling,ready',
            'position' => 'nullable|array', 'position.characterId' => 'required_with:position|integer', 'position.name' => 'required_with:position|string|max:50',
            'position.mapId' => 'required_with:position|integer|min:0|max:10000', 'position.mapName' => 'nullable|string|max:100',
            'position.zone' => 'required_with:position|integer|min:0|max:255', 'position.x' => 'required_with:position|integer', 'position.y' => 'required_with:position|integer']);
        return DB::transaction(function () use ($r, $id, $v, $activity) {
            $accountId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id');
            $account = NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $job = $this->job($r, $id);
            abort_unless($job->status === 'processing' && $job->worker_instance && $job->lease_until >= now()->toDateTimeString(), 409);
            abort_if(DB::table('nro_worker_jobs as j')->join('nro_delivery_sessions as s', 's.id', '=', 'j.delivery_session_id')
                ->where('j.account_id', $accountId)->whereIn('j.status', ['processing','review'])->where('s.trade_in_flight', true)->exists(), 409, 'Bot đang giao dịch, chưa được rời điểm giao.');
            $position = isset($v['position']) ? array_intersect_key($v['position'], array_flip(['characterId','name','mapId','mapName','zone','x','y'])) : null;
            $activity->update($account, $job->worker_instance, $v['phase'], $position);
            return response()->json(['ok' => true]);
        });
    }

    public function release(Request $r, int $id)
    {
        $r->validate(['workerInstance' => 'required|uuid']);
        return DB::transaction(function () use ($r, $id) {
            NroAccount::whereKey($id)->lockForUpdate()->firstOrFail();
            // Sent only after the last local reference has disconnected the game session.
            DB::table('nro_worker_jobs')->where('account_id', $id)->where('worker_instance', $r->input('workerInstance'))
                ->where('worker_key_id', $r->attributes->get('nro_worker_key_id'))->whereNotIn('status', ['queued', 'processing', 'review'])
                ->update(['worker_instance' => null, 'lease_until' => null]);
            return response()->json(['ok' => true]);
        });
    }

}
