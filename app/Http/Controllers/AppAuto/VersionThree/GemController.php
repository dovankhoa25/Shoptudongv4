<?php
namespace App\Http\Controllers\AppAuto\VersionThree;
use App\Events\TradingBotActivity;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppAuto\VersionThree\{ActivityRequest,BotUpdateRequest,GemOrderUpdateRequest};
use App\Http\Resources\AppAuto\VersionThree\{GemBotResource,GemOrderResource};
use App\Models\{GemBot,GemTransaction,ServerGameLogin};
use App\Services\TradingV3\GemTradingService;
use Illuminate\Http\Request;
class GemController extends Controller {
    
    public function config() { return response()->json(['success'=>true,'data'=>[
        'version'=>3,'login_warmup_seconds'=>config('trading_v3.login_warmup_seconds'),
        'gift_interval_seconds'=>config('trading_v3.gift_interval_seconds'),
        'lease_seconds'=>config('trading_v3.lease_seconds'),'activity_channel'=>'Trading.Bots',
    ]]); }
    public function servers() { return response()->json(['success'=>true,'data'=>ServerGameLogin::get(['id','ip','port','name'])]); }
    public function bots(Request $r) {
        return GemBotResource::collection(GemBot::with(['server','loginServer'])->when($r->filled('server_id'),fn($q)=>$q->where('server_id',$r->integer('server_id')))->orderBy('id')->get())->additional(['success'=>true]);
    }
    public function updateBot(BotUpdateRequest $r, GemBot $bot, GemTradingService $service) {
        return (new GemBotResource($service->updateBot($bot->id,$r->validated())))->additional(['success'=>true]);
    }
    public function orders(Request $r) {
        $r->validate(['worker_id'=>['required','uuid'],'server_id'=>['nullable','integer','min:1']]);
        $orders=GemTransaction::whereIn('status',['pending','processing'])
            ->where(fn($q)=>$q->where(fn($q)=>$q->where('status','pending')->whereNull('processing_version'))->orWhere(fn($q)=>$q->where('processing_version',3)->where('processing_owner',$r->worker_id)))
            ->when($r->filled('server_id'),fn($q)=>$q->where('server_id',$r->integer('server_id')))
            ->orderBy('id')->get();
        return GemOrderResource::collection($orders)->additional(['success'=>true]);
    }
    public function order(Request $r, GemTransaction $order) {
        $r->validate(['worker_id'=>['required','uuid']]);
        abort_unless($order->processing_owner === $r->worker_id,403,'Order belongs to another worker.');
        return (new GemOrderResource($order))->additional(['success'=>true]);
    }
    public function updateOrder(GemOrderUpdateRequest $r, GemTransaction $order, GemTradingService $service) {
        return (new GemOrderResource($service->updateOrder($order->id,$r->validated())))->additional(['success'=>true,'ack_id'=>$r->input('step_id')]);
    }
    public function activity(ActivityRequest $r, GemBot $bot) {
        $data=$r->validated();
        abort_unless($bot->v3_worker_id===$data['worker_id'],409,'Bot belongs to another worker.');
        $labels=['disabled'=>'Đã tắt','paused'=>'Đã tạm dừng Ngọc tại QLTK','connecting'=>'Đang kết nối','reconnecting'=>'Đang kết nối lại','reconciling'=>'Đang tự đối chiếu sau kết nối lại','login_wait'=>'Đang chờ đăng nhập',
            'travelling'=>'Bot đang đến điểm đứng','ready'=>'Sẵn sàng','warmup'=>'Bot vừa vào game, đang chờ',
            'waiting_customer'=>'Đang chờ khách giao dịch','giving_item'=>'Đang giao đồ ký gửi','waiting_consignment'=>'Đang chờ khách ký gửi',
            'buying'=>'Đang mua đồ ký gửi','gifting'=>'Đang tặng ngọc','syncing'=>'Đang gửi kết quả lên web',
            'review_required'=>'Cần đối soát','error'=>'Đang gặp lỗi','out_of_items'=>'Bot hết đồ ký gửi — liên hệ CSKH',
            'insufficient_gems'=>'Bot chưa đủ ngọc — liên hệ CSKH','out_of_tickets'=>'Bot hết vé tặng ngọc',
            'name_mismatch'=>'Tên đặt đơn chưa khớp ingame','customer_offline'=>'Đang chờ khách online',
            'trade_cancelled'=>'Giao dịch chưa hoàn tất','api_wait'=>'Đã xử lý trong game — đang chờ web xác nhận'];
        $orderId=$data['order_id']??null;$notice=$data['customer_message']??null;
        unset($data['worker_id'],$data['order_id'],$data['customer_message']);
        try {event(new TradingBotActivity(['bot_id'=>$bot->id,'bot_type'=>'gem_bot','server_id'=>(int)$bot->server_id,
            ...$data,'text'=>$labels[$data['code']],'observed_at'=>now()->toIso8601String(),
            'expires_at'=>now()->addSeconds(config('trading_v3.activity_ttl_seconds'))->toIso8601String()]));
            if($orderId && $notice) {
                $order=GemTransaction::findOrFail($orderId);
                abort_unless((int)$order->server_id===(int)$bot->server_id,422,'Notice server differs.');
                event(new \App\Events\UserEvent((int)$order->user_id,'bot_activity',$notice,
                    ['bot_id'=>$bot->id,'order_id'=>$order->id,'code'=>$data['code'],'message'=>$notice,'expires_at'=>now()->addSeconds(35)->toIso8601String()]));
            }
        } catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {throw $e;}
        catch(\Throwable $e) {\Illuminate\Support\Facades\Log::warning('Trading activity delivery failed',['bot_id'=>$bot->id]);return response()->json(['success'=>true,'activity_delivered'=>false]);}
        return response()->json(['success'=>true]);
    }
}

