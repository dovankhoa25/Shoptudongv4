<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

class NroDeliveryRound
{
    public static function begin(object $job, string $key, array $checkpoint): void {
        $lines=DB::table('item_order_items')->where('order_id',$job->order_id)->get()->keyBy('id');
        abort_unless($lines->count()===count($checkpoint),422);
        foreach($checkpoint as $line) {
            $item=$lines->get($line['id']);
            abort_unless($item && $line['delivered']==$item->delivered && $line['offered']<=$item->quantity-$item->delivered && $line['before']>=$line['offered'],422);
        }
        abort_unless(array_sum(array_column($checkpoint,'offered'))>0,422);
        $existing=DB::table('nro_delivery_rounds')->where('round_key',$key)->first();
        abort_if($existing,409,'Mã lượt giao đã được sử dụng.');
        DB::table('nro_delivery_rounds')->insert(['round_key'=>$key,'job_id'=>$job->id,'order_id'=>$job->order_id,
            'before_json'=>json_encode($checkpoint),'status'=>'started','created_at'=>now(),'updated_at'=>now()]);
        // The server can recover a lost tool without waiting for its local journal.
        DB::table('nro_worker_jobs')->where('id',$job->id)->update(['recovery_json'=>json_encode($checkpoint)]);
    }
    public static function finish(object $job, string $status, array $result=[]): void {
        DB::table('nro_delivery_rounds')->where('order_id',$job->order_id)->where('status','started')
            ->update(['status'=>$status,'result_json'=>json_encode($result),'updated_at'=>now()]);
        DB::table('nro_worker_jobs')->where('order_id',$job->order_id)->update(['recovery_json'=>null]);
    }
}
