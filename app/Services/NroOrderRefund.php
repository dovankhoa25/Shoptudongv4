<?php
namespace App\Services;

use App\Models\NroAccount;
use App\Models\User;
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;

class NroOrderRefund
{
    public static function eligible(object $o, ?bool $pendingRecovery = null): bool {
        return ((bool)$o->refund_requested || in_array($o->failure_code,['missing_items','login_failed','login_wait'])) && !($pendingRecovery ?? NroRoundRecovery::pending((int)$o->id));
    }
    public function request(int $id, User $actor): void {
        DB::transaction(function() use($id,$actor) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $o=DB::table('item_orders')->where('id',$id)->where('buyer_id',$actor->id)->first(); abort_unless($o,404);
            NroAccount::whereKey($o->account_id)->lockForUpdate()->firstOrFail();
            $o=DB::table('item_orders')->where('id',$id)->lockForUpdate()->first();
            if($o->status==='refunded') return;
            NroShopService::require(!DB::table('item_order_items')->where('order_id',$id)->where('delivered','>',0)->exists(),'Đã nhận một phần: tiếp tục nhận đủ, không thể hủy hoàn tiền.');
            NroShopService::require(self::eligible($o),'Chỉ yêu cầu hoàn khi kho thiếu đồ hoặc gặp lỗi đăng nhập.');
            NroShopService::require(!in_array($o->status,['review','completed']),'Kết quả giao chưa rõ hoặc đơn đã hoàn tất.');
            DB::table('item_orders')->where('id',$id)->update(['cancel_requested'=>true,'delivery_message'=>'Đã yêu cầu hủy. Đang dừng phiên an toàn trước khi hoàn tiền.','updated_at'=>now()]);
            foreach(DB::table('nro_worker_jobs')->where('order_id',$id)->where('status','queued')->get() as $j) {
                DB::table('nro_worker_jobs')->where('id',$j->id)->update(['status'=>'failed','updated_at'=>now()]);
                app(NroReceivingService::class)->finish($j,'failed');
            }
            if(!DB::table('nro_worker_jobs')->where('order_id',$id)->whereIn('status',['processing','review'])->exists()) DB::table('item_orders')->where('id',$id)->update(['status'=>'awaiting_receipt']);
        },3);
        $this->finishRequested($id);
    }
    public function finishRequested(int $id): void {
        $o=DB::table('item_orders')->find($id);
        if(!$o || !$o->cancel_requested || $o->status!=='awaiting_receipt') return;
        try { $this->run($id,User::findOrFail($o->buyer_id),(int)$o->price,$o->public_failure ?: 'Khách hủy vì kho chưa thể giao đồ.'); }
        catch(\Illuminate\Validation\ValidationException $e) { /* Still unsafe; keep cancellation visible. */ }
    }

    public function run(int $id, User $actor, int $amount, string $note): void
    {
        // Buyer cancellation is allowed only for a known failure before any delivery.
        DB::transaction(function () use ($id, $actor, $amount, $note) {
            $o = DB::table('item_orders')->find($id); abort_unless($o, 404);
            // Same buyer/account order as receiving and purchase; serializes new sessions.
            $buyer = User::whereKey($o->buyer_id)->lockForUpdate()->firstOrFail();
            $account = NroAccount::whereKey($o->account_id)->lockForUpdate()->firstOrFail();
            $o = DB::table('item_orders')->where('id', $id)->lockForUpdate()->first();
            $admin=$actor->hasAnyRole(['admin','super-admin']);
            abort_unless($admin || ((int)$o->buyer_id===$actor->id && ($o->cancel_requested || $o->status==='refunded')),403);
            if ($o->status === 'refunded') {
                NroShopService::require((int)$o->refund_amount === $amount, 'Đơn đã hoàn tiền; không thể hoàn thêm.');
                return;
            }
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id',$o->account_id)->whereNotNull('audit_order_id')->whereIn('status',['queued','processing'])->exists(), 'Chờ tool kiểm tra kho xong trước khi hoàn tiền.');
            NroShopService::require(!NroRoundRecovery::pending((int)$o->id), 'Bot cần khôi phục kết quả lượt giao trước khi xử lý hoàn tiền.');
            NroShopService::require($o->status === 'awaiting_receipt', 'Dừng phiên và đối soát kết quả trước khi hoàn tiền.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('order_id', $id)->whereIn('status', ['queued','processing','review'])->exists(), 'Đơn còn công việc đang chạy hoặc chờ đối soát.');
            NroShopService::require(!DB::table('nro_delivery_sessions')->where('order_id', $id)->whereIn('status', ['queued','preparing','ready','trading','review'])->exists(), 'Phiên nhận đồ chưa kết thúc hoặc chưa rõ kết quả.');
            $lines = DB::table('item_order_items')->where('order_id', $id)->get();
            NroShopService::require($lines->contains(fn($i)=>$i->delivered < $i->quantity), 'Đơn đã giao đủ, không còn phần chưa giao để hoàn.');
            NroShopService::require($amount >= 1 && $amount <= (int)$o->price - (int)$o->refund_amount, 'Số tiền hoàn vượt tiền còn lại của đơn.');
            $partial = $lines->contains(fn($i)=>$i->delivered > 0);
            NroShopService::require(!$partial, 'Đơn đã nhận một phần: tiếp tục nhận đủ, không thể hoàn tiền.');
            NroShopService::require($amount === (int)$o->price, 'Chưa giao món nào: phải hoàn đủ tiền đơn.');
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
            DB::table('item_orders')->where('id',$id)->update(['status'=>'refunded','cancel_requested'=>false,'refund_requested'=>false,'refund_amount'=>$amount,
                'refund_actor_id'=>$actor->id,'refund_note'=>$note,'refunded_at'=>now(),'updated_at'=>now(),
                'delivery_message'=>'Đã hoàn '.number_format($amount,0,',','.').'đ. Đơn đã kết thúc.']);
            $account->update(['last_synced_at'=>null]);
        }, 3);
        ApiCache::clearGroup('public:nro-shop:listings');
    }
}
