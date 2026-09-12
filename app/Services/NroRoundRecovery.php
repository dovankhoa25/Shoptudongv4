<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class NroRoundRecovery {
    public static function pending(int $order): bool {
        return DB::table('nro_worker_jobs')->where('order_id',$order)->whereNotNull('recovery_json')->exists();
    }
    public static function resumeAccount(int $account): void {
        $groups=DB::table('nro_worker_jobs')->where('account_id',$account)->whereNotNull('recovery_json')->orderBy('id')->get()->groupBy('order_id');
        foreach($groups as $orderId=>$jobs) {
            $order=DB::table('item_orders')->where('id',$orderId)->lockForUpdate()->first();
            if(!$order || in_array($order->status,['completed','refunded'])) continue;
            if(DB::table('nro_worker_jobs')->where('order_id',$orderId)->whereIn('status',['queued','processing','review'])->exists()) continue;
            $job=$jobs->last();
            DB::table('nro_worker_jobs')->insert(['account_id'=>$account,'order_id'=>$orderId,'delivery_session_id'=>$job->delivery_session_id,
                'type'=>'delivery','status'=>'queued','recovery_json'=>$job->recovery_json,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('nro_delivery_sessions')->where('id',$job->delivery_session_id)->update(['status'=>'queued','position_json'=>null,'updated_at'=>now()]);
            DB::table('item_orders')->where('id',$orderId)->update(['status'=>'queued','failure_code'=>null,'public_failure'=>null,'login_retry_at'=>null,
                'delivery_message'=>'Thông tin đăng nhập đã được kiểm tra; bot đang khôi phục lượt giao.','updated_at'=>now()]);
        }
    }
}
