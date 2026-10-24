<?php
namespace App\Http\Controllers\AppAuto\VersionThree;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppAuto\VersionThree\{GoldActivityRequest,GoldBotUpdateRequest,GoldOrderUpdateRequest,GoldTransferUpdateRequest};
use App\Http\Resources\AppAuto\VersionThree\{GoldBotResource,GoldOrderResource};
use App\Models\{Bot,GoldTransaction};
use App\Services\TradingV3\{GoldTradingService,TradingActivityPublisher};
use Illuminate\Http\Request;
class GoldController extends Controller {
    public function bots(Request $r) {
        $r->validate(['server_id'=>['nullable','integer','min:1']]);
        $bots=Bot::with(['server','loginServer'])
            ->when($r->filled('server_id'),fn($q)=>$q->where('server_id',$r->integer('server_id')))->orderBy('id')->get();
        return GoldBotResource::collection(GoldTradingService::prefetchReadStock($bots))->additional(['success'=>true]);
    }
    public function updateBot(GoldBotUpdateRequest $r,Bot $bot,GoldTradingService $service) {
        $updated=$service->updateBot($bot->id,$r->validated());
        GoldTradingService::prefetchReadStock(collect([$updated]));
        return (new GoldBotResource($updated))->additional(['success'=>true]);
    }
    public function orders(Request $r) {
        $r->validate(['worker_id'=>['nullable','uuid'],'server_id'=>['nullable','integer','min:1']]);
        $orders=GoldTransaction::whereIn('status',['pending','processing'])
            ->when($r->filled('server_id'),fn($q)=>$q->where('server_id',$r->integer('server_id')))->orderBy('id')->get();
        return GoldOrderResource::collection($orders)->additional(['success'=>true]);
    }
    public function order(Request $r,GoldTransaction $order) {
        $r->validate(['worker_id'=>['nullable','uuid']]);
        return (new GoldOrderResource($order))->additional(['success'=>true]);
    }
    public function updateOrder(GoldOrderUpdateRequest $r,GoldTransaction $order,GoldTradingService $service) {
        $result=$service->updateOrderWithReceipts($order->id,$r->validated());
        $receiptAcks=array_map(fn($receipt)=>['order_id'=>$receipt['order_id'],'step_id'=>$receipt['step_id'],
            'ack_id'=>$receipt['step_id'],'data'=>(new GoldOrderResource($receipt['order']))->resolve($r)],$result['receipts']);
        $transferAcks=array_map(fn($receipt)=>['transfer_id'=>$receipt['transfer_id'],'step_id'=>$receipt['step_id'],
            'ack_id'=>$receipt['step_id'],'data'=>$receipt['data']],$result['transfers']);
        return (new GoldOrderResource($result['order']))->additional(['success'=>true,'ack_id'=>$r->input('step_id'),
            'receipt_acks'=>$receiptAcks,'transfer_acks'=>$transferAcks]);
    }
    public function transfers(Request $r,GoldTradingService $service) {
        $r->validate(['worker_id'=>['nullable','uuid']]);
        return response()->json(['success'=>true,'data'=>$service->transferList()]);
    }
    public function updateTransfer(GoldTransferUpdateRequest $r,string $transfer,GoldTradingService $service) {
        return response()->json(['success'=>true,'ack_id'=>$r->input('step_id'),'data'=>$service->updateTransfer($transfer,$r->validated())]);
    }
    public function activity(GoldActivityRequest $r,Bot $bot) {
        $data=$r->validated();
        $orderId=$data['order_id']??null;$notice=$data['customer_message']??null;
        if($orderId && $notice) {
            $order=GoldTransaction::findOrFail($orderId);
            $unclaimed=$order->status==='pending'
                && ($order->bot_id===null || (int)$order->bot_id===(int)$bot->id);
            $owned=(int)$order->bot_id===(int)$bot->id;
            abort_unless((int)$order->server_id===(int)$bot->server_id && ($unclaimed || $owned),422,
                'Customer notice does not belong to this bot order.');
        }
        unset($data['worker_id'],$data['order_id'],$data['customer_message']);
        $labels=TradingActivityPublisher::labels('bot');
        $observed=now();$expires=$observed->copy()->addSeconds(config('trading_v3.activity_ttl_seconds'))->toIso8601String();
        $activity=['bot_id'=>$bot->id,'bot_type'=>'bot','server_id'=>(int)$bot->server_id,...$data,
            'text'=>$labels[$data['code']],'observed_at'=>$observed->toIso8601String(),'expires_at'=>$expires];
        $notices=$orderId && $notice ? [['user_id'=>(int)$order->user_id,'message'=>$notice,
            'payload'=>['bot_id'=>$bot->id,'order_id'=>$order->id,'order_type'=>'gold','code'=>$data['code'],'message'=>$notice,'expires_at'=>$expires]]] : [];
        return response()->json(['success'=>true,'activity_delivered'=>app(TradingActivityPublisher::class)->publish([$activity],$notices)]);
    }
}
