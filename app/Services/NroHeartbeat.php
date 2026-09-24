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
            if ($order && NroOrderFlow::terminal($order->status)) return ['id'=>$job->id,'ok'=>true,'final'=>true,'cancelRequested'=>true];
            $updates=[];
            if ($order && !$order->cancel_requested) {
                if ($input['loginWaiting'] ?? false) {
                    $retry=empty($input['loginRetryAt']) ? null : NroLoginMessage::retryAt($input['loginRetryAt'])->toDateTimeString();
                    $kind=$input['loginFailureKind'] ?? null; $code=NroLoginMessage::code($kind);
                    if ($order->failure_code!==$code || $order->login_retry_at!==$retry || ($order->failure_role ?? null)!==($input['loginAccountRole'] ?? null)) $updates+=['failure_code'=>$code,'failure_role'=>$input['loginAccountRole'] ?? null,'public_failure'=>NroLoginMessage::waiting($input['loginAccountRole'] ?? null,$kind),'login_retry_at'=>$retry];
                    if (NroLoginMessage::maintenance($kind)) NroLoginMessage::pauseForMaintenance($job);
                }
                if (array_key_exists('loginWaiting',$input) && !$input['loginWaiting'] && in_array($order->failure_code,NroLoginMessage::WAIT_CODES,true)) $updates += ['failure_code'=>null,'public_failure'=>null,'login_retry_at'=>null];
                if (!empty($input['message']) && $order->delivery_message!==$input['message']) $updates['delivery_message']=$input['message'];
                if ($updates) DB::table('item_orders')->where('id',$order->id)->update($updates+['updated_at'=>now()]);
            }
            $pulse=$order && Cache::add('nro:lease-pulse:'.$job->account_id,true,60);
            return ['id'=>$job->id,'ok'=>true,'cancelRequested'=>(bool)$order?->cancel_requested,
                '_order'=>$updates ? $job->order_id : null,'_account'=>$pulse ? $job->account_id : null];
        },3);
    }
}
