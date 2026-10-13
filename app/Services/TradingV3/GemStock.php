<?php
namespace App\Services\TradingV3;
use App\Models\{GemBot,GemTransaction};
class GemStock {
    public static function ownedByV3(GemBot $bot): bool {
        return ($bot->v3_worker_id && $bot->v3_lease_until?->isFuture()) || GemTransaction::where('bot_id',$bot->id)->where('processing_version',3)->where('status','processing')->exists();
    }
    public static function available(GemBot $bot,?int $excludeOrder=null): array {
        $orders=GemTransaction::where('bot_id',$bot->id)->whereIn('status',['pending','processing'])
            ->when($excludeOrder,fn($q)=>$q->whereKeyNot($excludeOrder))->get();
        $gems=0;$tickets=0;$items=0;
        foreach($orders as $o) {
            $remaining=max(0,(int)$o->gem_qty-(int)$o->bot_gem_spent_qty);$gems+=$remaining;
            if($o->delivery_method==='gift_ticket')$tickets+=intdiv($remaining,10);
            elseif(empty($o->delivery_data['delivery_item']['verified']))$items++;
        }
        return ['gems'=>max(0,(int)$bot->gem_qty-$gems),'tickets'=>max(0,(int)$bot->gift_ticket_qty-$tickets),
            'items'=>max(0,(int)$bot->consign_item_qty-$items)];
    }
    public static function giftCapacity(GemBot $bot): int {
        if(!config('trading_v3.enabled')||!$bot->status||!$bot->v3_worker_id||!$bot->v3_lease_until?->isFuture()||$bot->gift_ticket_qty===null)return 0;
        $a=self::available($bot);return min(intdiv($a['gems'],10),$a['tickets'])*10;
    }
    public static function reserveBot(int $server,int $gems): GemBot {
        abort_unless(config('trading_v3.enabled')&&$gems>0&&$gems%10===0,422,'Đơn vé cần số ngọc là bội của 10 và tool v3 đang bật.');
        $bots=GemBot::where('server_id',$server)->where('status',true)->whereNotNull('v3_worker_id')->where('v3_lease_until','>',now())
            ->whereNotNull('gift_ticket_qty')->orderBy('id')->lockForUpdate()->get();
        foreach($bots as $b)if(self::giftCapacity($b)>=$gems)return $b;
        abort(409,'Không còn đủ ngọc/vé khả dụng. Vui lòng chọn ký gửi hoặc liên hệ CSKH.');
    }
}

