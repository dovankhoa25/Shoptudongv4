<?php
namespace App\Services;

use App\Models\Nick;
use App\Models\NroAccount;
use App\Models\User;
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;

class NroAccountRemoval
{
    public function remove(int $id, User $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            // Nick purchases lock the listing first, then the game account.
            $nicks=Nick::withoutUserOwnedScope()->where('game_account_id',$id)->orderBy('id')->lockForUpdate()->get();
            $account=NroAccount::whereKey($id)->whereNotNull('usage_type')->lockForUpdate()->firstOrFail();
            abort_unless($actor->canViewAllAdminData() || $account->user_id===$actor->id,404);
            NroShopService::require(!DB::table('item_orders')->where('account_id',$id)->whereNotIn('status',NroOrderFlow::TERMINAL)->exists(),
                'Acc còn đơn chưa giao xong. Hoàn tất đơn trước khi xóa khỏi kho.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id',$id)->where(function($q) {
                $q->whereIn('status',['processing','review'])->orWhere('lease_until','>',now())
                    ->orWhere(fn($job)=>$job->where('status','queued')->where('type','!=','snapshot'));
            })->exists(), 'Tool đang xử lý hoặc còn lượt giao cần kiểm tra. Dừng phiên và xử lý xong trước khi xóa.');
            NroShopService::require(!DB::table('nro_delivery_sessions')->whereIn('order_id',DB::table('item_orders')->select('id')->where('account_id',$id))
                ->whereIn('status',NroOrderFlow::ACTIVE_SESSIONS)->exists(), 'Acc còn phiên nhận đồ chưa kết thúc.');
            NroShopService::require(!DB::table('nro_inventory_items')->where('account_id',$id)->where('reserved','>',0)->exists()
                && !DB::table('item_inventory_reservations')->whereIn('inventory_item_id',DB::table('nro_inventory_items')->select('id')->where('account_id',$id))
                    ->where('status','held')->where('quantity','>',0)->exists(), 'Acc còn đồ được giữ cho đơn. Kiểm tra lượt giao trước khi xóa.');

            // Queued scans have not logged in; cancel them while holding the same lock as claim.
            DB::table('nro_worker_jobs')->where('account_id',$id)->where('status','queued')->where('type','snapshot')
                ->update(['status'=>'cancelled','result_json'=>json_encode(['message'=>'Tài khoản đã được xóa khỏi kho.','actorId'=>$actor->id]),'updated_at'=>now()]);
            DB::table('item_listings')->where('account_id',$id)->whereIn('status',['active','paused','draft'])
                ->update(['status'=>'archived','updated_at'=>now()]);
            foreach ($nicks as $nick) if ($nick->status==='not_sold') { $nick->status='deleted'; $nick->save(); }
            $account->update(['status'=>$account->status==='sold' ? 'sold' : 'archived', 'shop_hidden'=>true, 'auto_publish'=>false]);
            $account->delete(); // Retain inventory, snapshots, orders and audit history.
            ApiCache::clearGroups(['public:nick','public:nro-shop:listings']);
        }, 3);
    }
}
