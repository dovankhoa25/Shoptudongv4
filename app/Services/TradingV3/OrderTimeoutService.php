<?php
namespace App\Services\TradingV3;
use App\Models\{GemTransaction,GoldTransaction,Transaction};
use App\Services\{TransactionService,UserRealtimeNotifier};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Pending cancellation and timeout refunds are shared by the tool and scheduler. */
class OrderTimeoutService {
    public function cancelLocked(Model $order,int $minutes,string $type,?int $grace=null): bool {
        if($order->status!=='pending'||$order->cancel_requested_at!==null||!$order->created_at
            ||$order->created_at->gt(now()->subMinutes($minutes)))return false;
        $data=$order->delivery_data??[];
        $data['timeout_policy']=['timeout_minutes'=>$minutes,'refund_grace_minutes'=>$grace??max(0,(int)config("trading.{$type}_order_refund_grace_minutes",5))];
        $order->forceFill(['status'=>'cancelled','updated_by'=>'web','last_synced_at'=>now(),
            'cancel_requested_at'=>now(),'delivery_data'=>$data])->save();
        DB::afterCommit(fn()=>app(UserRealtimeNotifier::class)->orderStatus((int)$order->user_id,$type,$order->id,'cancelled',$order->bot_id));
        return true;
    }
    /** Called while the order is locked inside the caller's DB transaction. */
    public function refundPendingLocked(Model $order,int $seconds,string $type='gem'): bool {
        if($seconds<1||$seconds>86400
            ||!($type==='gem'&&$order instanceof GemTransaction || $type==='gold'&&$order instanceof GoldTransaction))return false;
        if($order instanceof GoldTransaction&&$order->type!==GoldTransaction::TYPE_ORDER)return false;
        if($order->status!=='pending'||$order->cancel_requested_at!==null||!$order->created_at
            ||$order->created_at->gt(now()->subSeconds($seconds)))return false;
        // A stale pending label must not turn a verified game delivery into a refund.
        if($order->processing_token)return false;
        if($order instanceof GemTransaction&&($order->bot_gem_spent_qty||$order->delivered_gem_qty||$order->tickets_used_qty
            ||!empty($order->delivery_data['delivery_item']['verified'])))return false;
        if($order instanceof GoldTransaction&&(!empty($order->delivery_data['progress']['bars'])
            ||!empty($order->delivery_data['progress']['pure'])||!empty($order->delivery_data['last_step_id'])))return false;
        $data=$order->delivery_data??[];
        $data['timeout_policy']=['timeout_seconds'=>$seconds,'refund_grace_minutes'=>0];
        $order->forceFill(['status'=>'cancelled','updated_by'=>'web','last_synced_at'=>now(),
            'cancel_requested_at'=>now(),'delivery_data'=>$data])->save();
        // Cancel and credit commit together. Gold preserves its existing cancelled + refunded_at schema.
        $this->refundLocked($order,$type,0);
        return $order->refunded_at!==null;
    }
    public function refundLocked(Model $order,string $type,?int $defaultGrace=null): bool {
        if($order->status!=='cancelled'||$order->refunded_at!==null||!$order->cancel_requested_at
            ||($order instanceof GoldTransaction&&$order->type!==GoldTransaction::TYPE_ORDER))return false;
        $grace=max(0,(int)($order->delivery_data['timeout_policy']['refund_grace_minutes']??$defaultGrace??config("trading.{$type}_order_refund_grace_minutes",5)));
        if($order->cancel_requested_at->gt(now()->subMinutes($grace)))return false;
        $key="{$type}-order-timeout-refund:{$order->id}";
        if(Transaction::withoutUserOwnedScope()->where('idempotency_key',$key)->lockForUpdate()->exists()){$this->markRefunded($order,$type);return false;}
        $amount=(int)$order->amount_vnd;
        if($amount<=0){$this->markRefunded($order,$type);return false;}
        $user=$order->user()->lockForUpdate()->firstOrFail();$before=(int)$user->balance;
        if($before>TransactionService::MAX_BALANCE-$amount)throw ValidationException::withMessages(['balance'=>"Không thể hoàn tiền đơn #{$order->id}: số dư vượt giới hạn."]);
        $after=$before+$amount;$user->forceFill(['balance'=>$after])->save();
        TransactionService::log(userId:(int)$user->id,type:$type==='gem'?Transaction::TYPE_GEM_ORDER_REFUND:Transaction::TYPE_GOLD_ORDER_REFUND,
            amount:$amount,description:"Hoàn tiền tự động đơn mua ".($type==='gem'?'ngọc':'vàng')." quá hạn #{$order->id} - Nhân vật: {$order->character_name}",
            performedBy:null,related:$order,relatedId:$order->id,oldBalance:$before,newBalance:$after,idempotencyKey:$key,
            metadata:['source'=>'timeout','reason'=>'stale_order','character_name'=>$order->character_name]);
        $this->markRefunded($order,$type);
        DB::afterCommit(function()use($order,$user,$amount,$after,$type){
            app(UserRealtimeNotifier::class)->balanceChanged((int)$user->id,$amount,$after,"Đơn #{$order->id} đã được hoàn tiền do quá thời gian xử lý.");
            if($type==='gem')app(UserRealtimeNotifier::class)->orderStatus((int)$order->user_id,$type,$order->id,'refunded',$order->bot_id);
        });
        return true;
    }
    public function expirePending(string $model,int $minutes,string $type): int {
        $count=0;
        $model::where('status','pending')->whereNull('cancel_requested_at')->where('created_at','<=',now()->subMinutes($minutes))->select('id')
            ->chunkById(100,function($orders)use($model,$minutes,$type,&$count){foreach($orders as $candidate){
                $changed=DB::transaction(function()use($model,$candidate,$minutes,$type){$order=$model::whereKey($candidate->id)->lockForUpdate()->first();return $order&&$this->cancelLocked($order,$minutes,$type);},3);
                if($changed)$count++;
            }});
        return $count;
    }
    public function refundElapsed(string $model,int $grace,string $type): int {
        $count=0;
        $model::where('status','cancelled')->whereNull('refunded_at')->whereNotNull('cancel_requested_at')
            ->when($model===GoldTransaction::class,fn($q)=>$q->where('type',GoldTransaction::TYPE_ORDER))->select('id')
            ->chunkById(100,function($orders)use($model,$grace,$type,&$count){foreach($orders as $candidate){
                $changed=DB::transaction(function()use($model,$candidate,$grace,$type){$order=$model::whereKey($candidate->id)->lockForUpdate()->first();return $order&&$this->refundLocked($order,$type,$grace);},3);
                if($changed)$count++;
            }});
        return $count;
    }
    private function markRefunded(Model $order,string $type): void {
        $order->forceFill(['updated_by'=>'web','last_synced_at'=>now(),'refunded_at'=>now(),...($type==='gem'?['status'=>GemTransaction::STATUS_REFUNDED]:[])])->save();
    }
}
