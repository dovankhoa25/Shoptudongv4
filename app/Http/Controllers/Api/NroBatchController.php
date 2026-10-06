<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NroAccount;
use App\Services\{NroDeliveryRound,NroShopService,NroSnapshotService,NroWarehouseActivity};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Every game trade owns an immutable manifest. Receipts may be retried or piggybacked. */
class NroBatchController extends Controller
{
    private function child(Request $r,array $body): Request {
        $child=Request::create('/','POST',$body);
        $child->attributes->add($r->attributes->all());
        return $child;
    }
    private function owned(Request $r,int $id,array $member,bool $live): object {
        $job=DB::table('nro_worker_jobs')->where('id',$member['jobId'])->lockForUpdate()->first();
        abort_unless($job && (int)$job->account_id===$id && ($job->delivery_protocol ?? 4)>=5
            && hash_equals($job->lease_token ?? '',$member['leaseToken']),403);
        if ($live) abort_unless($job->worker_instance===$r->input('workerInstance')
            && (int)$job->worker_key_id===(int)$r->attributes->get('nro_worker_key_id')
            && $job->status==='processing' && $job->lease_until>=now()->toDateTimeString(),409);
        return $job;
    }
    private function receiptRules(): array {
        return ['batchKey'=>'required|uuid','outcome'=>'required|in:success,cancelled', 'disrupted'=>'sometimes|boolean',
            'members'=>'required|array|min:1|max:50','members.*.jobId'=>'required|integer|distinct','members.*.leaseToken'=>'required|uuid',
            'members.*.roundKey'=>'required|uuid|distinct','members.*.items'=>'required|array|min:1|max:20',
            'members.*.items.*.id'=>'required|integer','members.*.items.*.delivered'=>'required|integer|min:0'];
    }
    private function applyReceipt(Request $r,int $id,array $input): void {
        validator($input,$this->receiptRules())->validate();
        $digest=hash('sha256',json_encode($input));
        $accepted=DB::table('nro_batch_receipts')->where('batch_key',$input['batchKey'])->first();
        if($accepted) {abort_unless($accepted->account_id==$id && hash_equals($accepted->digest,$digest),422);return;}
        foreach($input['members'] as $member) {
            $this->owned($r,$id,$member,false);
            abort_unless(count(array_unique(array_column($member['items'],'id')))===count($member['items']),422);
        }
        $remember=fn()=>DB::table('nro_batch_receipts')->insert(['batch_key'=>$input['batchKey'],'account_id'=>$id,'digest'=>$digest,'created_at'=>now()]);
        $rows=DB::table('nro_delivery_rounds')->where('batch_key',$input['batchKey'])->get()->keyBy('job_id');
        if($rows->isEmpty() && $input['outcome']==='cancelled') {$remember();return;}
        abort_unless($rows->count()===count($input['members']),409,'Chưa có đầy đủ bản ghi bắt đầu lượt giao.');
        $controller=app(NroWorkerController::class);
        foreach ($input['members'] as $member) {
            $job=$this->owned($r,$id,$member,false); $round=$rows->get($job->id);
            abort_unless($round && $round->round_key===$member['roundKey'],422);
            if ($input['outcome']==='success') {
                $controller->progress($this->child($r,['leaseToken'=>$member['leaseToken'],'roundKey'=>$member['roundKey'],'items'=>$member['items']]),$job->id,app(NroSnapshotService::class));
                if (!DB::table('item_order_items')->where('order_id',$job->order_id)->whereColumn('delivered','<','quantity')->exists())
                    $controller->complete($this->child($r,['leaseToken'=>$member['leaseToken'],'outcome'=>'success','tradeEvidence'=>'server_success']),$job->id,app(NroSnapshotService::class),app(NroShopService::class));
            } else {
                abort_unless(in_array($round->status,['started','cancelled']),409);
                $session=DB::table('nro_delivery_sessions')->where('id',$job->delivery_session_id)->first();
                $live=in_array($job->status,['processing','review']) && $session && in_array($session->status,['trading','review']) && $session->trade_in_flight;
                if($round->status==='started' && !$live) {
                    // An expired attempt may already have a replacement job. Resolve the old round without ending that replacement.
                    NroDeliveryRound::finish($job,'cancelled',['evidence'=>'server_cancelled']);
                    if($session && in_array($session->status,['queued','preparing','ready','trading','review'])) {
                        DB::table('nro_delivery_sessions')->where('id',$session->id)->update(['trade_in_flight'=>false,'trade_phase'=>null,'phase_deadline'=>null]);
                        if(!($input['disrupted'] ?? false)) {
                            DB::table('nro_worker_jobs')->where('delivery_session_id',$session->id)->whereIn('status',['queued','processing','review'])->update(['status'=>'failed','updated_at'=>now()]);
                            DB::table('nro_delivery_sessions')->where('id',$session->id)->update(['status'=>'suspended','retry_at'=>now()->addMinutes(5),'receiver_credentials'=>null,'receiver_lock'=>null,'updated_at'=>now()]);
                            DB::table('item_orders')->where('id',$job->order_id)->whereNotIn('status',['completed','refunded'])->update(['status'=>'awaiting_receipt','delivery_message'=>'Giao dịch đã hủy. Có thể nhận lại sau 5 phút.','updated_at'=>now()]);
                        }
                    }
                }
                if ($round->status==='started' && $live) $controller->complete($this->child($r,['leaseToken'=>$member['leaseToken'],
                    'outcome'=>($input['disrupted'] ?? false)?'trade_recovered':'trade_paused','tradeEvidence'=>'server_cancelled']),$job->id,app(NroSnapshotService::class),app(NroShopService::class));
            }
            $r->attributes->set('nro_changed_order_ids',[...$r->attributes->get('nro_changed_order_ids',[]),(int)$job->order_id]);
        }
        $remember();
        if($input['outcome']==='success') $r->attributes->set('nro_stock_changed',true);
        // Release other customers' wait clocks after this game trade, preserving the rendezvous.
        $account=NroAccount::findOrFail($id);$state=$account->delivery_activity ?? [];
        if (($state['phase'] ?? null)==='trading') app(NroWarehouseActivity::class)->update($account,$state['workerInstance'] ?? '', 'ready',$state['position'] ?? null);
    }
    public function receipt(Request $r,int $id) {
        abort_if(strlen($r->getContent())>4*1024*1024,413);
        return DB::transaction(function() use($r,$id) {
            NroAccount::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->applyReceipt($r,$id,$r->all());return response()->json(['ok'=>true]);
        },3);
    }
    public function begin(Request $r,int $id) {
        $v=$r->validate(['workerInstance'=>'required|uuid','batchKey'=>'required|uuid','recipientName'=>'required|string|max:50','characterId'=>'required|integer',
            'receipts'=>'sometimes|array|max:100','members'=>'required|array|min:1|max:50','members.*.jobId'=>'required|integer|distinct',
            'members.*.leaseToken'=>'required|uuid','members.*.roundKey'=>'required|uuid|distinct','members.*.checkpoint'=>'required|array|min:1|max:20',
            'members.*.checkpoint.*.id'=>'required|integer','members.*.checkpoint.*.before'=>'required|integer|min:0',
            'members.*.checkpoint.*.offered'=>'required|integer|min:0','members.*.checkpoint.*.delivered'=>'required|integer|min:0']);
        abort_if(strlen($r->getContent())>4*1024*1024,413);
        // Commit queued receipts independently: a rejected new invitation must not roll back past deliveries.
        foreach ($v['receipts'] ?? [] as $receipt) DB::transaction(function() use($r,$id,$receipt) {
            NroAccount::whereKey($id)->lockForUpdate()->firstOrFail();$this->applyReceipt($r,$id,$receipt);
        },3);
        return DB::transaction(function() use($r,$id,$v) {
            $account=NroAccount::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('nro_batch_receipts')->where('batch_key',$v['batchKey'])->exists(),409,'Lượt giao này đã kết thúc.');
            $jobs=[];$buyer=null;
            foreach($v['members'] as $member) {
                $job=$this->owned($r,$id,$member,true);
                $order=DB::table('item_orders')->where('id',$job->order_id)->first();
                $session=DB::table('nro_delivery_sessions')->where('id',$job->delivery_session_id)->first();
                abort_unless($order,404);
                abort_unless(count(array_unique(array_column($member['checkpoint'],'id')))===count($member['checkpoint']),422);
                $buyer ??= $order->buyer_id;
                abort_unless($order && $order->buyer_id==$buyer && !$order->cancel_requested && !in_array($order->status,['refunded','completed'])
                    && $session && mb_strtolower($session->recipient_name ?? '')===mb_strtolower($v['recipientName'])
                    && in_array($session->status,['ready','trading']) && $session->expires_at>now()->toDateTimeString(),409);
                if($session->recipient_character_id!==null) abort_unless((int)$session->recipient_character_id===$v['characterId'],422);
                $existing=DB::table('nro_delivery_rounds')->where('round_key',$member['roundKey'])->first();
                if($existing) abort_unless($existing->batch_key===$v['batchKey'] && $existing->job_id==$job->id && $existing->status==='started'
                    && json_decode($existing->before_json,true)===$member['checkpoint'],409);
                $jobs[]=[$job,$member,$existing];
            }
            $ids=array_map(fn($row)=>$row[0]->id,$jobs);
            abort_if(DB::table('nro_worker_jobs')->where('account_id',$id)->whereNotIn('id',$ids)->whereNotNull('recovery_json')->whereIn('status',['queued','processing'])->exists(),409,'Kho đang khôi phục lượt trước.');
            abort_if(DB::table('nro_worker_jobs as j')->join('nro_delivery_sessions as s','s.id','=','j.delivery_session_id')->where('j.account_id',$id)
                ->whereNotIn('j.id',$ids)->where('s.trade_in_flight',true)->whereIn('j.status',['processing','review'])->exists(),409);
            foreach($jobs as [$job,$member,$existing]) {
                if(!$existing) {
                    NroDeliveryRound::begin($job,$member['roundKey'],$member['checkpoint']);
                    DB::table('nro_delivery_rounds')->where('round_key',$member['roundKey'])->update(['batch_key'=>$v['batchKey']]);
                }
                DB::table('nro_delivery_sessions')->where('id',$job->delivery_session_id)->update(['status'=>'trading','recipient_character_id'=>$v['characterId'],
                    'trade_in_flight'=>true,'trade_phase'=>'locking','phase_deadline'=>now()->addSeconds(40),'updated_at'=>now()]);
                $r->attributes->set('nro_changed_order_ids',[...$r->attributes->get('nro_changed_order_ids',[]),(int)$job->order_id]);
            }
            app(NroWarehouseActivity::class)->update($account,$v['workerInstance'],'trading',null,$ids[0]);
            return response()->json(['ok'=>true]);
        },3);
    }
    public static function currentStock(int $id,?string $key): bool {
        if(DB::table('nro_delivery_rounds as r')->join('nro_worker_jobs as j','j.id','=','r.job_id')->where('j.account_id',$id)->where('r.status','started')->exists()) return false;
        $last=DB::table('nro_delivery_rounds as r')->join('nro_worker_jobs as j','j.id','=','r.job_id')->where('j.account_id',$id)->orderByDesc('r.id')->value('r.batch_key');
        return $last===$key;
    }
    public function stock(Request $r,int $id) {
        $v=$r->validate(['workerInstance'=>'required|uuid','jobId'=>'required|integer','leaseToken'=>'required|uuid','receipts'=>'sometimes|array|max:100','payload'=>'required|array','afterBatchKey'=>'nullable|uuid']);
        return DB::transaction(function() use($r,$id,$v) {
            $account=NroAccount::whereKey($id)->lockForUpdate()->firstOrFail();
            $job=$this->owned($r,$id,$v,false);
            abort_unless($job->worker_instance===$v['workerInstance'] && $job->lease_until>=now()->toDateTimeString(),409);
            foreach($v['receipts'] ?? [] as $receipt) $this->applyReceipt($r,$id,$receipt);
            abort_if(DB::table('nro_worker_jobs')->where('account_id',$id)->where('worker_instance','!=',$v['workerInstance'])->where('status','processing')->exists(),409);
            abort_if(DB::table('nro_delivery_rounds as r')->join('nro_worker_jobs as j','j.id','=','r.job_id')->where('j.account_id',$id)->where('r.status','started')->exists(),409,'Kết quả lượt giao chưa gửi đủ.');
            $last=DB::table('nro_delivery_rounds as r')->join('nro_worker_jobs as j','j.id','=','r.job_id')->where('j.account_id',$id)->orderByDesc('r.id')->value('r.batch_key');
            abort_unless($last===($v['afterBatchKey'] ?? null),409,'Kho đã có lượt giao mới sau dữ liệu này.');
            $snapshot=app(NroSnapshotService::class)->ingest($account,$v['payload']);return response()->json(['ok'=>true,'snapshotId'=>$snapshot->id]);
        },3);
    }
}
