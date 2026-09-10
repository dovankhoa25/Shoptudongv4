<?php
namespace App\Services;

use App\Models\NroAccount;
use Illuminate\Support\Facades\DB;

class NroDeliveryLifecycle
{
    /** Only safe, unfinished receipt sessions can resume; an uncertain round never replays. */
    public function recover(object $job): bool
    {
        if (!$job->order_id || !$job->delivery_session_id) return false;
        $s=DB::table('nro_delivery_sessions')->where('id',$job->delivery_session_id)->lockForUpdate()->first();
        $o=DB::table('item_orders')->where('id',$job->order_id)->lockForUpdate()->first();
        if (!$s || !$o || $s->trade_in_flight === null || (bool)$s->trade_in_flight) return false;
        if (in_array($o->status,['completed','refunded'])) return false;
        if (!DB::table('item_order_items')->where('order_id',$o->id)->whereColumn('delivered','<','quantity')->exists()) return false;
        $expired=$s->expires_at && $s->expires_at <= now()->toDateTimeString();
        $resume=!$expired && !$o->cancel_requested && !$o->refund_requested && (!$o->failure_code || $o->failure_code==='login_wait');
        DB::table('nro_worker_jobs')->where('id',$job->id)->update(['status'=>'failed','result_json'=>json_encode(['reason'=>'connection_lost','retrySafe'=>true,'resumed'=>$resume]),'updated_at'=>now()]);
        if (!DB::table('nro_worker_jobs')->where('account_id',$job->account_id)->where('id','!=',$job->id)->where('status','processing')->where('lease_until','>',now())->exists())
            NroAccount::whereKey($job->account_id)->update(['delivery_activity'=>null]);
        if ($resume) {
            // Keep the original receiver, deadline and encrypted credentials. Replace only the execution attempt.
            DB::table('nro_delivery_sessions')->where('id',$s->id)->update(['status'=>'queued','position_json'=>null,'updated_at'=>now()]);
            DB::table('nro_worker_jobs')->insert(['account_id'=>$job->account_id,'order_id'=>$o->id,'delivery_session_id'=>$s->id,'type'=>'delivery','status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
            DB::table('item_orders')->where('id',$o->id)->update(['status'=>'queued','delivery_message'=>'Bot mất kết nối; đang khôi phục phiên nhận và xác nhận lại điểm giao.','updated_at'=>now()]);
        } else {
            app(NroReceivingService::class)->finish($job,'failed');
            DB::table('item_orders')->where('id',$o->id)->update(['status'=>'awaiting_receipt','delivery_message'=>$o->public_failure ?: 'Phiên nhận đã kết thúc. Phần chưa nhận vẫn được giữ; bấm nhận lại khi sẵn sàng.','updated_at'=>now()]);
        }
        return true;
    }

    /** Runs without a worker too. Each account/job is locked and rechecked independently. */
    public function expire(): array
    {
        $changed=[];
        DB::table('nro_worker_jobs')->where('status','processing')->where('lease_until','<',now())->orderBy('id')->chunkById(100,function($jobs) use (&$changed) {
            foreach($jobs as $candidate) DB::transaction(function() use($candidate,&$changed) {
                $a=NroAccount::whereKey($candidate->account_id)->lockForUpdate()->first();
                $j=DB::table('nro_worker_jobs')->where('id',$candidate->id)->lockForUpdate()->first();
                if(!$j || $j->status!=='processing' || $j->lease_until>=now()->toDateTimeString()) return;
                $changed[]=(int)$j->account_id;
                if($this->recover($j)) return;
                $status=$j->type==='snapshot'?'failed':'review';
                DB::table('nro_worker_jobs')->where('id',$j->id)->update(['status'=>$status,'updated_at'=>now()]);
                if($j->type==='snapshot' && $a) NroSnapshotRetries::failed($a->id,'Tool mất kết nối. Bấm Lấy dữ liệu để thử lại.');
                if($j->order_id) DB::table('item_orders')->where('id',$j->order_id)->whereNotIn('status',['completed','refunded'])->update(['status'=>'review','delivery_message'=>'Mất kết nối trong lượt giao chưa xác nhận. Shop đang kiểm tra kết quả.','updated_at'=>now()]);
                app(NroReceivingService::class)->finish($j,$status);
            },3);
        });
        DB::table('nro_worker_jobs as j')->join('nro_delivery_sessions as s','s.id','=','j.delivery_session_id')
            ->whereIn('j.status',['queued','processing'])->whereIn('s.status',['queued','ready'])->where('s.trade_in_flight',false)
            ->whereNotNull('s.expires_at')->where('s.expires_at','<=',now())->select('j.*')->orderBy('j.id')->chunkById(100,function($jobs) use(&$changed) {
                foreach($jobs as $candidate) DB::transaction(function() use($candidate,&$changed) {
                    $a=NroAccount::whereKey($candidate->account_id)->lockForUpdate()->first();
                    $j=DB::table('nro_worker_jobs')->where('id',$candidate->id)->lockForUpdate()->first();
                    $s=$j ? DB::table('nro_delivery_sessions')->where('id',$j->delivery_session_id)->lockForUpdate()->first() : null;
                    if(!$a || !$j || !$s || !in_array($j->status,['queued','processing']) || !in_array($s->status,['queued','ready']) || $s->trade_in_flight || !$s->expires_at || $s->expires_at>now()->toDateTimeString()) return;
                    if(($a->delivery_activity['pauseStartedAt'] ?? null)!==null) return;
                    DB::table('nro_worker_jobs')->where('id',$j->id)->update(['status'=>'expired','result_json'=>json_encode(['reason'=>'receipt_timeout','retrySafe'=>true]),'updated_at'=>now()]);
                    app(NroReceivingService::class)->finish($j,'expired');
                    DB::table('item_orders')->where('id',$j->order_id)->whereNotIn('status',['completed','refunded'])->update(['status'=>'awaiting_receipt','delivery_message'=>'Hết thời gian chờ. Phần chưa nhận vẫn giữ cho đơn; bấm nhận lại khi sẵn sàng.','updated_at'=>now()]);
                    $changed[]=(int)$j->account_id;
                },3);
            },'j.id','id');
        // Refunds acquire buyer -> account locks outside the expiration transaction.
        foreach(DB::table('item_orders')->where('cancel_requested',true)->where('status','awaiting_receipt')->pluck('id') as $id) app(NroOrderRefund::class)->finishRequested($id);
        return array_values(array_unique($changed));
    }
}
