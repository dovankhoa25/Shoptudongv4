<?php
namespace App\Services;

use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NroOrderStockCheck
{
    public function request(int $id, User $actor): int
    {
        abort_unless($actor->can('item-orders.reconcile') || ($actor->can('nro-accounts.manage') && $actor->can('item-orders.view')),403);
        return DB::transaction(function () use ($id, $actor) {
            $o=DB::table('item_orders')->find($id); abort_unless($o,404);
            $a=NroAccount::whereKey($o->account_id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canViewAllAdminData() || $a->user_id===$actor->id,404);
            $o=DB::table('item_orders')->where('id',$id)->lockForUpdate()->first();
            NroShopService::require(in_array($o->status,['review','awaiting_receipt']), 'Chỉ kiểm tra kho của đơn chờ nhận hoặc chờ đối soát.');
            NroShopService::require($a->status==='active' && $a->publish_status!=='login_blocked', 'Acc kho đang ngừng hoạt động hoặc bị chặn đăng nhập. Sửa thông tin acc trước.');
            $pending=DB::table('nro_worker_jobs')->where('account_id',$a->id)->whereNotNull('audit_order_id')->whereIn('status',['queued','processing'])->first();
            if ($pending) {
                NroShopService::require((int)$pending->audit_order_id===$id, 'Kho đang được kiểm tra cho đơn #'.$pending->audit_order_id.'. Chờ kết quả trước khi kiểm tra đơn này.');
                return $pending->id;
            }
            // An expired review can be inspected; a still-owned game session cannot be logged into twice.
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id',$a->id)->where(function($q) {
                $q->where(fn($j)=>$j->whereIn('status',['processing','review'])->where(fn($l)=>$l->whereNull('lease_until')->where('status','processing')->orWhere('lease_until','>=',now())))
                  ->orWhere(fn($j)=>$j->whereNotNull('worker_instance')->where('lease_until','>=',now()));
            })->exists(), 'Bot kho còn phiên đang chạy. Dừng bot của acc này và chờ quyền giữ phiên hết hạn rồi kiểm tra.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id',$a->id)->where('type','snapshot')->where('status','queued')->exists(), 'Acc đã có công việc lấy dữ liệu đang chờ.');
            return DB::table('nro_worker_jobs')->insertGetId(['account_id'=>$a->id,'type'=>'snapshot','status'=>'queued',
                'audit_order_id'=>$id,'audit_requested_by'=>$actor->id,'created_at'=>now(),'updated_at'=>now()]);
        },3);
    }

    public function cancel(int $id, User $actor): void
    {
        abort_unless($actor->can('item-orders.reconcile') || ($actor->can('nro-accounts.manage') && $actor->can('item-orders.view')),403);
        DB::transaction(function() use($id,$actor) {
            $o=DB::table('item_orders')->find($id); abort_unless($o,404);
            $a=NroAccount::whereKey($o->account_id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canViewAllAdminData() || $a->user_id===$actor->id,404);
            $job=DB::table('nro_worker_jobs')->where('audit_order_id',$id)->whereIn('status',['queued','processing'])->lockForUpdate()->first();
            if(!$job) return;
            NroShopService::require($job->status==='queued' || ($job->lease_until && $job->lease_until < now()->toDateTimeString()), 'Tool đang kiểm tra kho. Dừng phiên và chờ hết quyền giữ phiên trước khi hủy.');
            DB::table('nro_worker_jobs')->where('id',$job->id)->update(['status'=>'failed','updated_at'=>now(),
                'result_json'=>json_encode(['message'=>'Đã hủy yêu cầu kiểm tra kho. Trạng thái giao đồ giữ nguyên.','cancelActorId'=>$actor->id])]);
        },3);
    }

    public function report(int $id): array
    {
        $order=app(NroShopService::class)->order($id);
        $job=DB::table('nro_worker_jobs')->where('audit_order_id',$id)->orderByDesc('id')->first();
        $result=$job ? json_decode($job->result_json ?: '{}',true) : [];
        $snapshot=$job && $job->status==='completed' && !empty($result['snapshotId'])
            ? NroAccountSnapshot::whereKey($result['snapshotId'])->where('account_id',$job->account_id)->first() : null;
        $complete=$snapshot && ($snapshot->completeness_json['bag'] ?? false) && ($snapshot->completeness_json['chest'] ?? false) && ($snapshot->completeness_json['equipped'] ?? false);
        $stock=$complete ? array_merge($snapshot->data_json['bag'] ?? [],$snapshot->data_json['chest'] ?? [],$snapshot->data_json['equipped'] ?? []) : [];
        $lines=DB::table('item_order_items')->where('order_id',$id)->orderBy('id')->get();
        $other=DB::table('item_inventory_reservations')->whereIn('inventory_item_id',$lines->pluck('inventory_item_id'))->where('order_id','!=',$id)->where('status','held')
            ->selectRaw('inventory_item_id, SUM(quantity) as quantity')->groupBy('inventory_item_id')->pluck('quantity','inventory_item_id');
        $rows=$lines->map(function($line) use($stock,$complete,$other) {
            $item=json_decode($line->item_json,true); $identity=NroSnapshotService::identity($item);
            $count=$complete ? array_sum(array_map(fn($i)=>NroSnapshotService::identity($i)===$identity ? $i['quantity'] : 0,$stock)) : null;
            $held=(int)($other[$line->inventory_item_id] ?? 0); $need=max(0,$line->quantity-$line->delivered);
            return ['id'=>$line->id,'item'=>$item,'quantity'=>(int)$line->quantity,'delivered'=>(int)$line->delivered,'remaining'=>$need,
                'inStock'=>$count,'heldForOthers'=>$held,'available'=>$complete?max(0,$count-$held):null,'missing'=>$complete?max(0,$need-max(0,$count-$held)):null];
        })->values();
        $review=DB::table('nro_worker_jobs')->where('order_id',$id)->where('status','review')->orderByDesc('id')->get(['id','lease_until']);
        $accountId=DB::table('item_orders')->where('id',$id)->value('account_id');
        $busy=DB::table('nro_worker_jobs')->where('account_id',$accountId)->whereNotNull('audit_order_id')->whereIn('status',['queued','processing'])->exists();
        return ['order'=>$order,'check'=>$job?['id'=>$job->id,'status'=>$job->status,'requestedAt'=>$job->created_at,'message'=>$result['message'] ?? null,
            'requestedBy'=>User::whereKey($job->audit_requested_by)->value('username'),'capturedAt'=>$snapshot?->captured_at]:null,
            'complete'=>(bool)$complete,'items'=>$rows,'reviewJobs'=>$review,'checking'=>$busy];
    }
}
