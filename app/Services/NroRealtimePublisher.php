<?php
namespace App\Services;
use App\Events\NroOrdersPatched;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class NroRealtimePublisher
{
    public function snapshot(array $ids): array
    {
        return DB::transaction(function () use ($ids) {
            $owners=DB::table('item_orders')->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->pluck('buyer_id','id');
            DB::table('item_orders')->whereIn('id',$owners->keys())->increment('realtime_revision');
            return [$owners,app(NroShopService::class)->orders($owners->keys())];
        },3);
    }
    public function push(array $orderIds, string $action = ''): array
    {
        $buyers=[];
        foreach (array_chunk(array_values(array_unique(array_filter($orderIds))),100) as $ids) {
            // Lock before versioning and reading; late events cannot roll back a client.
            [$owners,$payloads]=$this->snapshot($ids);
            $grouped=[];
            foreach ($payloads as $id=>$order) {
                $buyer=(int)$owners[$id];$buyers[]=$buyer;
                $patch=Arr::only($order,['id','revision','status','message','recipientName','botLeaseUntil','botOnline','failureCode','publicFailure','loginRetryAt','cancelRequested','canCancel','refundRequested','refundAmount','refundedAt','session','botActivity','deliveryLocation']);
                $patch['itemProgress']=array_map(fn($i)=>Arr::only($i,['id','delivered']),$order['items']);
                $grouped[$buyer][]=$patch;
            }
            foreach ($grouped as $buyer=>$orders) {
                $batch=[];
                foreach ($orders as $patch) {
                    $money=$action==='purchase' || $patch['status']==='refunded';
                    if (strlen(json_encode($patch))>7500) { broadcast(new NroOrdersPatched($buyer,[],$money,true));continue; }
                    if ($batch && strlen(json_encode([...$batch,$patch]))>7500) {
                        broadcast(new NroOrdersPatched($buyer,$batch,$action==='purchase' || collect($batch)->contains('status','refunded')));$batch=[];
                    }
                    $batch[]=$patch;
                }
                if ($batch) broadcast(new NroOrdersPatched($buyer,$batch,$action==='purchase' || collect($batch)->contains('status','refunded')));
            }
        }
        return array_values(array_unique($buyers));
    }
}
