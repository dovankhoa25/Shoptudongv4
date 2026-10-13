<?php
namespace App\Http\Controllers\AppAuto\VersionThree;
use App\Events\{TradingBotActivity,UserEvent};
use App\Http\Controllers\Controller;
use App\Http\Requests\AppAuto\VersionThree\{GoldActivityRequest,GoldBotUpdateRequest,GoldOrderUpdateRequest,GoldTransferUpdateRequest};
use App\Http\Resources\AppAuto\VersionThree\{GoldBotResource,GoldOrderResource};
use App\Models\{Bot,GoldTransaction};
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
class GoldController extends Controller {
    public function bots(Request $r) {
        $r->validate(['server_id'=>['nullable','integer','min:1']]);
        return GoldBotResource::collection(Bot::with(['server','loginServer'])
            ->when($r->filled('server_id'),fn($q)=>$q->where('server_id',$r->integer('server_id')))->orderBy('id')->get())->additional(['success'=>true]);
    }
    public function updateBot(GoldBotUpdateRequest $r,Bot $bot,GoldTradingService $service) {
        return (new GoldBotResource($service->updateBot($bot->id,$r->validated())))->additional(['success'=>true]);
    }
    public function orders(Request $r) {
        $r->validate(['worker_id'=>['required','uuid'],'server_id'=>['nullable','integer','min:1']]);
        $orders=GoldTransaction::whereIn('status',['pending','processing'])
            ->where(fn($q)=>$q->where(fn($q)=>$q->where('status','pending')->whereNull('processing_version'))
                ->orWhere(fn($q)=>$q->where('processing_version',3)->where('processing_owner',$r->worker_id)))
            ->when($r->filled('server_id'),fn($q)=>$q->where('server_id',$r->integer('server_id')))->orderBy('id')->get();
        return GoldOrderResource::collection($orders)->additional(['success'=>true]);
    }
    public function order(Request $r,GoldTransaction $order) {
        $r->validate(['worker_id'=>['required','uuid']]);
        abort_unless($order->processing_owner===$r->worker_id,403,'Order belongs to another worker.');
        return (new GoldOrderResource($order))->additional(['success'=>true]);
    }
    public function updateOrder(GoldOrderUpdateRequest $r,GoldTransaction $order,GoldTradingService $service) {
        return (new GoldOrderResource($service->updateOrder($order->id,$r->validated())))->additional(['success'=>true,'ack_id'=>$r->input('step_id')]);
    }
    public function transfers(Request $r,GoldTradingService $service) {
        $r->validate(['worker_id'=>['required','uuid']]);
        return response()->json(['success'=>true,'data'=>$service->transferList($r->worker_id)]);
    }
    public function updateTransfer(GoldTransferUpdateRequest $r,string $transfer,GoldTradingService $service) {
        return response()->json(['success'=>true,'ack_id'=>$r->input('step_id'),'data'=>$service->updateTransfer($transfer,$r->validated())]);
    }
    public function activity(GoldActivityRequest $r,Bot $bot) {
        $data=$r->validated();abort_unless($bot->v3_worker_id===$data['worker_id'],409,'Bot belongs to another worker.');
        $orderId=$data['order_id']??null;$notice=$data['customer_message']??null;
        if($orderId && $notice) {
            $order=GoldTransaction::findOrFail($orderId);
            abort_unless((int)$order->server_id===(int)$bot->server_id && (int)$order->bot_id===$bot->id
                && $order->processing_owner===$data['worker_id'],422,'Customer notice does not belong to this bot order.');
        }
        unset($data['worker_id'],$data['order_id'],$data['customer_message']);
        $labels=['disabled'=>'Đã tắt','paused'=>'Đã tạm dừng Vàng tại QLTK','connecting'=>'Đang kết nối','reconnecting'=>'Đang kết nối lại',
            'reconciling'=>'Đang tự đối chiếu giao dịch vàng','login_wait'=>'Đang chờ đăng nhập','travelling'=>'Bot đang đến điểm đứng',
            'ready'=>'Sẵn sàng','warmup'=>'Bot vừa vào game, đang chờ','waiting_customer'=>'Đang chờ khách giao dịch',
            'waiting_stock'=>'Đang chờ bơm vàng hoặc giải phóng kho','trading'=>'Đang giao dịch vàng','selling'=>'Đang giao vàng cho khách',
            'importing'=>'Đang nhận vàng từ khách','transferring'=>'Đang chuyển vàng giữa các bot','storage_idle'=>'Bot phụ đang nghỉ',
            'source_empty'=>'Bot phụ hết nguồn vàng','syncing'=>'Đang gửi kết quả lên web','error'=>'Đang thử phục hồi',
            'name_mismatch'=>'Tên đặt đơn chưa khớp ingame','customer_offline'=>'Đang chờ khách online',
            'trade_cancelled'=>'Giao dịch chưa hoàn tất','api_wait'=>'Đã xử lý trong game — đang chờ web xác nhận'];
        $labels += ['giving_bars'=>'Đang giao vàng cho khách','receiving_bars'=>'Đang nhận vàng từ khách','storage'=>'Đang gom vàng vào bot phụ',
            'refilling'=>'Đang bơm vàng cho bot bán','reserve_idle'=>'Bot phụ đang nghỉ','capacity_full'=>'Bot đã đầy sức chứa vàng',
            'out_of_bars'=>'Bot chưa đủ thỏi vàng'];
        try {
            event(new TradingBotActivity(['bot_id'=>$bot->id,'bot_type'=>'bot','server_id'=>(int)$bot->server_id,...$data,
                'text'=>$labels[$data['code']],'observed_at'=>now()->toIso8601String(),
                'expires_at'=>now()->addSeconds(config('trading_v3.activity_ttl_seconds'))->toIso8601String()]));
            if($orderId && $notice) event(new UserEvent((int)$order->user_id,'bot_activity',$notice,
                ['bot_id'=>$bot->id,'order_id'=>$order->id,'order_type'=>'gold','code'=>$data['code'],'message'=>$notice,'expires_at'=>now()->addSeconds(35)->toIso8601String()]));
        } catch(\Throwable $e) {
            Log::warning('Trading gold activity delivery failed',['bot_id'=>$bot->id]);
            return response()->json(['success'=>true,'activity_delivered'=>false]);
        }
        return response()->json(['success'=>true]);
    }
}
