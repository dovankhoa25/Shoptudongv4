<?php
namespace App\Http\Middleware;

use App\Events\NroShopUpdated;
use App\Services\NroRealtimePublisher;
use App\Support\ApiCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PublishNroChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        $response=$next($request);
        if($request->isMethodSafe() || !$response->isSuccessful()) return $response;
        $action=$request->route()?->getActionMethod();
        $heartbeat=in_array($action,['heartbeat','heartbeatBatch']);
        if($heartbeat && !$request->attributes->get('nro_delivery_message_changed')) return $response;
        $body=$response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : [];
        $maintenance=(array)$request->attributes->get('nro_changed_accounts',[]);
        $accounts=$maintenance;
        $orders=(array)$request->attributes->get('nro_changed_order_ids',[]);
        if($action==='claim') {
            if(!empty($body['data']['account']['id'])) $accounts[]=(int)$body['data']['account']['id'];
            if(!$accounts) return $response;
        }
        try {
            $id=(int)$request->route('id');
            if(!$heartbeat) {
                if($request->is('app/nro-worker/jobs/*','admin/nro-shop/jobs/*')) {
                    $job=DB::table('nro_worker_jobs')->where('id',$id)->first(['account_id','order_id']);
                    if($job) {
                        $accounts[]=(int)$job->account_id;
                        if($job->order_id) {
                            $orders[]=(int)$job->order_id;
                            if($action==='complete') app(\App\Services\NroOrderRefund::class)->finishRequested((int)$job->order_id);
                        }
                    }
                } elseif($request->is('*/nro-shop/accounts/*','app/nro-worker/accounts/*')) $accounts[]=$id;
                elseif($request->is('api/nro-shop/orders*','admin/nro-shop/orders/*')) {
                    $orderId=$id ?: (int)($body['data']['id'] ?? 0);
                    if($orderId) $orders[]=$orderId;
                }
            }
            $accounts=array_values(array_unique(array_filter($accounts)));
            // Only a warehouse phase/lease update fans out to other waiting buyers.
            if($accounts) $orders=[...$orders,...DB::table('item_orders')->whereIn('account_id',$accounts)->whereNotIn('status',['completed','refunded'])->pluck('id')->all()];
            $orders=array_values(array_unique(array_filter($orders)));
            $catalog=$action==='claim' ? ((bool)$maintenance || ($body['data']['type'] ?? '')==='snapshot') : !in_array($action,['release','resultIssue','ready','tradePhase','beginRound','warehouseState','receive','heartbeat','heartbeatBatch','stockCheck']);
            DB::afterCommit(function () use($catalog,$orders,$action) {
                if($catalog) ApiCache::clearGroup('public:nro-shop:listings');
                $buyers=$orders ? DB::table('item_orders')->whereIn('id',$orders)->distinct()->pluck('buyer_id')->map(fn($id)=>(int)$id)->all() : [];
                $pushed=false;
                if($orders) {
                    try { app(NroRealtimePublisher::class)->push($orders,(string)$action);$pushed=true; }
                    catch(\Throwable $e) { Log::warning('NRO direct update failed; falling back to invalidation',['error'=>$e->getMessage()]); }
                }
                // Old clients retain their invalidation contract during rolling deployment.
                // Updated clients ignore this signal when direct patches were delivered.
                $adminResources=in_array($action,['heartbeat','heartbeatBatch','ready','tradePhase','beginRound','warehouseState'])
                    ? ['nro:orders','nro:jobs','nro:account-detail','nro:status'] : ['nro'];
                broadcast(new NroShopUpdated((string)Str::uuid(),$catalog,$buyers,$pushed,$adminResources));
            });
        } catch(\Throwable $e) { Log::warning('NRO realtime publish failed',['error'=>$e->getMessage()]); }
        return $response;
    }
}
