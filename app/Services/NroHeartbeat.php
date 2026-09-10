<?php
namespace App\Services;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NroHeartbeat
{
    public function renew(int $keyId, array $input, ?string $instance = null): array
    {
        return DB::transaction(function () use ($keyId,$input,$instance) {
            $job=DB::table('nro_worker_jobs')->where('id',$input['id'])->lockForUpdate()->first();
            abort_unless($job && (int)$job->worker_key_id===$keyId && hash_equals($job->lease_token ?? '',$input['leaseToken']) && ($instance===null || $job->worker_instance===$instance),403);
            if (in_array($job->status,['completed','failed','expired'])) return ['id'=>$job->id,'ok'=>true,'final'=>true];
            abort_unless($job->status==='processing' && $job->lease_until>=now()->toDateTimeString(),409);
            DB::table('nro_worker_jobs')->where('id',$job->id)->update(['lease_until'=>now()->addMinutes(3),'updated_at'=>now()]);
            $order=$job->order_id ? DB::table('item_orders')->where('id',$job->order_id)->lockForUpdate()->first() : null;
            $updates=[];
            if ($order && !$order->cancel_requested) {
                if ($input['loginWaiting'] ?? false) {
                    $retry=empty($input['loginRetryAt']) ? null : \Carbon\Carbon::parse($input['loginRetryAt'])->toDateTimeString();
                    if ($order->failure_code!=='login_wait' || $order->login_retry_at!==$retry) $updates+=['failure_code'=>'login_wait','public_failure'=>'Game đang giới hạn đăng nhập. Hãy chờ hoặc yêu cầu hủy nếu chưa nhận đồ.','login_retry_at'=>$retry];
                }
                if (!empty($input['message']) && $order->delivery_message!==$input['message']) $updates['delivery_message']=$input['message'];
                if ($updates) DB::table('item_orders')->where('id',$order->id)->update($updates+['updated_at'=>now()]);
            }
            $pulse=$order && Cache::add('nro:lease-pulse:'.$job->account_id,true,60);
            return ['id'=>$job->id,'ok'=>true,'cancelRequested'=>(bool)$order?->cancel_requested,
                '_order'=>$updates ? $job->order_id : null,'_account'=>$pulse ? $job->account_id : null];
        },3);
    }
}
