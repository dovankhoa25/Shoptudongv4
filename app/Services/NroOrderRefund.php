<?php
namespace App\Services;

use App\Models\NroAccount;
use App\Models\User;
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;

class NroOrderRefund
{
    public function run(int $id, User $actor, int $amount, string $note): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super-admin']), 403);
        DB::transaction(function () use ($id, $actor, $amount, $note) {
            $o = DB::table('item_orders')->find($id); abort_unless($o, 404);
            // Same buyer/account order as receiving and purchase; serializes new sessions.
            $buyer = User::whereKey($o->buyer_id)->lockForUpdate()->firstOrFail();
            $account = NroAccount::whereKey($o->account_id)->lockForUpdate()->firstOrFail();
            $o = DB::table('item_orders')->where('id', $id)->lockForUpdate()->first();
            if ($o->status === 'refunded') {
                NroShopService::require((int)$o->refund_amount === $amount, 'Đơn đã hoàn tiền; không thể hoàn thêm.');
                return;
            }
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id',$o->account_id)->whereNotNull('audit_order_id')->whereIn('status',['queued','processing'])->exists(), 'Chờ tool kiểm tra kho xong trước khi hoàn tiền.');
            NroShopService::require($o->status === 'awaiting_receipt', 'Dừng phiên và đối soát kết quả trước khi hoàn tiền.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('order_id', $id)->whereIn('status', ['queued','processing','review'])->exists(), 'Đơn còn công việc đang chạy hoặc chờ đối soát.');
            NroShopService::require(!DB::table('nro_delivery_sessions')->where('order_id', $id)->whereIn('status', ['queued','preparing','ready','trading','review'])->exists(), 'Phiên nhận đồ chưa kết thúc hoặc chưa rõ kết quả.');
            $lines = DB::table('item_order_items')->where('order_id', $id)->get();
            NroShopService::require($lines->contains(fn($i)=>$i->delivered < $i->quantity), 'Đơn đã giao đủ, không còn phần chưa giao để hoàn.');
            NroShopService::require($amount >= 1 && $amount <= (int)$o->price - (int)$o->refund_amount, 'Số tiền hoàn vượt tiền còn lại của đơn.');
            $partial = $lines->contains(fn($i)=>$i->delivered > 0);
            NroShopService::require($partial || $amount === (int)$o->price, 'Chưa giao món nào: phải hoàn đủ tiền đơn.');
            foreach (DB::table('item_inventory_reservations')->where('order_id',$id)->where('status','held')->lockForUpdate()->get() as $res) {
                DB::table('nro_inventory_items')->where('id',$res->inventory_item_id)->decrement('reserved',$res->quantity);
            }
            DB::table('item_inventory_reservations')->where('order_id',$id)->where('status','held')->update(['status'=>'released','updated_at'=>now()]);
            $credit = function(User $user, int $value, string $type, string $key) use($id,$actor,$note) {
                NroShopService::require((int)$user->balance <= TransactionService::MAX_BALANCE-$value, 'Số dư vượt giới hạn.');
                $before=(int)$user->balance; $user->increment('balance',$value);
                TransactionService::log(userId:$user->id,type:$type,amount:$value,description:'Đơn đồ #'.$id.' · '.$note,
                    performedBy:$actor->id,related:'nro_item_order',relatedId:$id,oldBalance:$before,newBalance:$before+$value,idempotencyKey:$key);
            };
            $credit($buyer,$amount,'refund_nro_items',"nro-order:$id:admin-refund");
            // The administrator sets the refund for a partially delivered bundle.
            // The unrefunded remainder settles the delivered part with its seller.
            $remainder=(int)$o->price-$amount;
            if($remainder>0) $credit(User::whereKey($o->seller_id)->lockForUpdate()->firstOrFail(),$remainder,'sell_nro_items',"nro-order:$id:partial-settle");
            DB::table('item_orders')->where('id',$id)->update(['status'=>'refunded','refund_requested'=>false,'refund_amount'=>$amount,
                'refund_actor_id'=>$actor->id,'refund_note'=>$note,'refunded_at'=>now(),'updated_at'=>now(),
                'delivery_message'=>'Admin đã hoàn '.number_format($amount,0,',','.').'đ. Đơn đã kết thúc.']);
            $account->update(['last_synced_at'=>null]);
        }, 3);
        ApiCache::clearGroup('public:nro-shop:listings');
    }
}
