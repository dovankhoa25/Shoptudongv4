<?php
namespace App\Services\TradingV3;
use App\Models\{GemBot,GemTransaction,InventoryMovement};
use App\Services\{BotHistoryService,UserRealtimeNotifier};
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class GemTradingService {
    public function updateBot(int $id, array $d): GemBot {
        $visibilityChanged=$d['action']!=='lease';
        $bot=DB::transaction(function() use($id,$d,&$visibilityChanged) {
            $bot=GemBot::whereKey($id)->lockForUpdate()->firstOrFail();
            if($d['action']==='lease') {
                abort_unless($bot->status && $bot->server?->status,409,'Bot or server is disabled.');
                abort_if($bot->v3_worker_id && $bot->v3_worker_id!==$d['worker_id'] && $bot->v3_lease_until?->isFuture(),409,'Bot is leased to another worker.');
                abort_if(GemTransaction::where('bot_id',$id)->where('status','processing')->where(fn($q)=>$q->whereNull('processing_version')->orWhere('processing_version',2))->exists(),409,'Tool v2 has an unfinished order on this bot.');
                abort_if(GemTransaction::where('bot_id',$id)->where('status','processing')
                    ->whereNotNull('processing_owner')->where('processing_owner','!=',$d['worker_id'])->exists(),409,'Another worker has an unfinished order.');
                $leaseUntil=now()->addSeconds(config('trading_v3.lease_seconds'));
                $visibilityChanged=!$bot->v3_worker_id || !$bot->v3_lease_until?->isFuture() || $leaseUntil->lt($bot->v3_lease_until);
                $bot->forceFill(['v3_worker_id'=>$d['worker_id'],'v3_lease_until'=>$leaseUntil])->save();
            } else {
                $this->assertOwner($bot,$d['worker_id']);
                if($d['action']==='release') {
                    abort_if(GemTransaction::where('bot_id',$id)->where('status','processing')->exists(),409,'Unfinished orders must retain their owner.');
                    $bot->forceFill(['v3_worker_id'=>null,'v3_lease_until'=>null])->save();
                } else {
                    abort_if(GemTransaction::where('bot_id',$id)->where('status','processing')->exists(),409,'Stock correction waits until orders are reconciled.');
                    $this->applySnapshot($bot,$d,'sync_correction',null,null);
                }
            }
            return $bot->fresh('server');
        },3);
        if($visibilityChanged) ApiCache::clearGroups(['public:gembot','public:server-prices']);
        return $bot;
    }
    private function assertOwner(GemBot $bot,string $worker): void {
        abort_unless($bot->v3_worker_id===$worker,409,'Bot belongs to another worker.');
    }
    public function updateOrder(int $id,array $d): GemTransaction {
        $order=DB::transaction(function() use($id,$d) {
            // All writes lock bot before order, including claims and completion.
            $bot=GemBot::whereKey($d['bot_id'])->lockForUpdate()->firstOrFail();
            $order=GemTransaction::whereKey($id)->lockForUpdate()->firstOrFail();
            // A committed update may be ACKed after the bot was released or taken over.
            // This path never changes bot/order/stock, and authenticates the original order owner and frozen body.
            if($d['action']!=='claim' && isset($d['step_id'])) {
                $suffix=$d['action']==='receipt'?'receipt':'phase';
                $previous=InventoryMovement::where('idempotency_key',"trading-v3:gem:{$id}:{$d['step_id']}:{$suffix}")->first();
                if($previous) {
                    abort_unless($order->processing_version===3 && $order->processing_owner===$d['worker_id'] && (int)$order->bot_id===$bot->id
                        && hash_equals((string)$order->processing_token,$d['processing_token']),409,'Original order owner does not match.');
                    abort_unless(hash_equals((string)($previous->meta['payload_hash']??''),$this->digest($d)),409,'Acknowledged body differs from original.');
                    return $order;
                }
            }
            $this->assertOwner($bot,$d['worker_id']);
            abort_unless((int)$bot->server_id===(int)$order->server_id,422,'Order and bot server_id differ.');
            if($d['action']==='claim') {
                if($order->processing_version===3 && $order->processing_owner===$d['worker_id'] && (int)$order->bot_id===$bot->id) return $order;
                abort_unless($bot->status && $bot->server?->status && $bot->v3_lease_until?->isFuture(),409,'Bot is not available.');
                abort_unless($order->status==='pending' && $order->processing_version===null,409,'Order was already claimed or is terminal.');
                abort_if($order->bot_id && (int)$order->bot_id!==$bot->id,409,'Order is assigned to another bot.');
                $available=GemStock::available($bot,$order->id);
                abort_unless($available['gems'] >= $order->gem_qty,409,'Gem inventory is reserved for other orders.');
                if($order->delivery_method==='gift_ticket') {
                    abort_unless($order->gem_qty>0 && $order->gem_qty%10===0,422,'Ticket orders require multiples of 10.');
                    abort_unless($bot->gift_ticket_qty!==null && $available['tickets'] >= $order->gem_qty/10,409,'Insufficient verified ticket stock.');
                } else abort_unless($available['items']>0,409,'Insufficient consignment stock.');
                $order->forceFill(['bot_id'=>$bot->id,'status'=>'processing','processing_version'=>3,
                    'delivery_data'=>['claim'=>['character_name'=>$order->character_name,'gem_qty'=>(int)$order->gem_qty,'delivery_method'=>$order->delivery_method]],
                    'processing_owner'=>$d['worker_id'],'processing_token'=>(string)Str::uuid(),
                    'delivery_phase'=>'waiting_customer','processing_started_at'=>now(),'updated_by'=>'app'])->save();
                return $order;
            }
            abort_unless($order->processing_version===3 && $order->processing_owner===$d['worker_id']
                && (int)$order->bot_id===$bot->id && hash_equals((string)$order->processing_token,$d['processing_token']),409,'Order processing owner does not match.');
            $claim=$order->delivery_data['claim']??[];
            abort_unless(($claim['character_name']??null)===$order->character_name && ($claim['gem_qty']??null)===(int)$order->gem_qty
                && ($claim['delivery_method']??null)===$order->delivery_method,409,'Order terms changed after claim; reconciliation is required.');
            if($d['action']==='phase') {
                $phaseKey=isset($d['step_id']) ? "trading-v3:gem:{$id}:{$d['step_id']}:phase" : null;
                abort_unless($order->status==='processing',409,'Order is terminal.');
                abort_if(!empty($d['delivery_data']['delivery_item']['verified']),422,'Equipment is verified only by a confirmed trade receipt.');
                if(isset($d['character_id'])) {
                    abort_if($order->character_id && (int)$order->character_id!==$d['character_id'],409,'Recipient ID changed.');
                    $order->character_id=$d['character_id'];
                }
                $order->delivery_phase=$d['phase'];
                $order->delivery_data=array_replace_recursive($order->delivery_data??[],$d['delivery_data']??[]);
                $order->save();
                if($phaseKey) InventoryMovement::create(['server_id'=>$bot->server_id,'bot_id'=>$bot->id,'bot_type'=>'gem_bot',
                    'asset_type'=>'workflow','movement_type'=>'phase','quantity_delta'=>0,'balance_before'=>0,'balance_after'=>0,
                    'transaction_id'=>$id,'transaction_type'=>'gem_transaction','idempotency_key'=>$phaseKey,
                    'source'=>'app','meta'=>['payload_hash'=>$this->digest($d)],'occurred_at'=>now()]);
                return $order;
            }
            $key="trading-v3:gem:{$id}:{$d['step_id']}:receipt";
            $digest=$this->digest($d);
            abort_unless($order->status==='processing',409,'Order is terminal.');
            abort_unless($order->character_id && (!isset($d['character_id']) || $d['character_id']===$order->character_id),422,'A verified recipient ID is required.');
            $spent=$d['gem_spent_qty'];$received=$d['delivered_gem_qty'];$tickets=$d['tickets_used_qty'];
            abort_if($order->bot_gem_spent_qty+$spent>$order->gem_qty,422,'Receipt exceeds order quantity.');
            if($order->delivery_method==='gift_ticket') {
                abort_unless($d['evidence']['kind']==='gift' && $spent>0 && $spent%10===0 && $received===$spent && $tickets===intdiv($spent,10),422,'Ticket receipt quantities do not match.');
            } else {
                abort_unless(in_array($d['evidence']['kind'],['trade','consignment'],true) && $tickets===0,422,'Consignment evidence does not match method.');
                if($d['evidence']['kind']==='trade') {
                    abort_unless($spent===0 && $received===0 && !$d['completed'],422,'Trade only delivers equipment.');
                    $previousPlan=$order->delivery_data['delivery_item']??[];$plan=$d['delivery_data']['delivery_item']??[];
                    abort_unless(empty($previousPlan['verified']) && !empty($plan['verified']) && !empty($plan['signature'])
                        && $plan['signature']===($previousPlan['signature']??null) && (int)($plan['quantity']??0)===1,422,'Trade receipt must verify the frozen equipment plan once.');
                }
                else abort_unless($spent>0 && $received<=$spent && !empty($order->delivery_data['delivery_item']['verified']),422,'Verified bot equipment is required.');
            }
            $snap=$d['bot_snapshot'];
            abort_unless((int)$snap['gem_qty']===(int)$bot->gem_qty-$spent,409,'Gem snapshot does not match acknowledged receipts.');
            abort_unless((int)$snap['gift_ticket_qty']===(int)$bot->gift_ticket_qty-$tickets,409,'Ticket snapshot does not match acknowledged receipts.');
            $itemDelta=match($d['evidence']['kind']){'trade'=>-1,'consignment'=>1,default=>0};
            abort_unless((int)$snap['consign_item_qty']===(int)$bot->consign_item_qty+$itemDelta,409,'Equipment snapshot does not match receipt.');
            if($d['completed']) abort_unless($order->bot_gem_spent_qty+$spent===(int)$order->gem_qty,422,'Order has not been fully delivered.');
            $this->applySnapshot($bot,$snap,'sale',$order,$key,['payload_hash'=>$digest,'evidence'=>$d['evidence']]);
            $order->bot_gem_spent_qty+=$spent;$order->delivered_gem_qty+=$received;$order->tickets_used_qty+=$tickets;
            $order->delivery_data=array_replace_recursive($order->delivery_data??[],$d['delivery_data']??[]);
            $deliveryData=$order->delivery_data;
            $deliveryData['last_step_id']=$d['step_id'];
            $order->delivery_data=$deliveryData;
            $order->delivery_phase=$d['completed']?'completed':($order->delivery_method==='gift_ticket'?'gifting':'waiting_consignment');
            if($d['completed']) {$order->status='completed';$order->completed_at=now();}
            $order->updated_by='app';$order->last_synced_at=now();$order->save();
            DB::afterCommit(fn()=>app(UserRealtimeNotifier::class)->orderStatus((int)$order->user_id,'gem',$order->id,$order->status,$bot->id));
            return $order;
        },3);
        ApiCache::clearGroups(['public:gembot']);
        return $order->fresh();
    }
    private function applySnapshot(GemBot $bot,array $s,string $reason,?GemTransaction $order,?string $receiptKey,array $meta=[]): void {
        abort_unless((int)$s['stock_version']>(int)$bot->stock_version,409,'Stock snapshot is stale.');
        $fields=['name','gem_qty','gift_ticket_qty','consign_item_qty'];$old=$bot->only($fields);
        $bot->forceFill([...array_intersect_key($s,array_flip($fields)),'stock_version'=>$s['stock_version'],'updated_by'=>'app','last_synced_at'=>now()])->save();
        foreach(['gem_qty'=>'gem','gift_ticket_qty'=>'gem_ticket','consign_item_qty'=>'consign_item'] as $field=>$asset) {
            $before=(int)($old[$field]??0);$after=(int)$bot->$field;
            if($before===$after && !($receiptKey && $field==='gem_qty')) continue;
            InventoryMovement::create(['server_id'=>$bot->server_id,'bot_id'=>$bot->id,'bot_type'=>'gem_bot','asset_type'=>$asset,
                'movement_type'=>$reason,'quantity_delta'=>$after-$before,'balance_before'=>$before,'balance_after'=>$after,
                'transaction_id'=>$order?->id,'transaction_type'=>$order?'gem_transaction':null,
                'idempotency_key'=>$receiptKey?($field==='gem_qty'?$receiptKey:$receiptKey.':'.$asset):null,
                'source'=>$order?'order':'app','meta'=>$meta?:null,'note'=>$order?'V3 confirmed game receipt':'V3 inventory reconciliation','occurred_at'=>now()]);
        }
        BotHistoryService::logUpdate('gem_bot',$bot,$old,$bot->only($fields),$order?'order':'app',
            category:'runtime',transactionId:$order?->id,transactionType:$order?'gem_transaction':null,note:'Trading v3 stock update');
    }
    private function digest(array $value): string {
        $sort=function(array $a) use (&$sort): array {ksort($a);foreach($a as $k=>$v)if(is_array($v))$a[$k]=$sort($v);return $a;};
        return hash('sha256',json_encode($sort($value),JSON_THROW_ON_ERROR));
    }
}

