<?php
namespace App\Services\TradingV3;
use App\Models\{GemBot,GemTransaction,InventoryMovement};
use App\Services\{BotHistoryService,UserRealtimeNotifier};
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;
class GemTradingService {
    public function updateBot(int $id, array $d): GemBot {
        // Old binaries may still send these actions. They are compatibility reads,
        // never permission grants, ownership changes or inventory corrections.
        if(in_array($d['action'],['lease','release'],true)) return GemBot::with('server')->findOrFail($id);
        $bot=DB::transaction(function() use($id,$d) {
            $bot=GemBot::whereKey($id)->lockForUpdate()->firstOrFail();
            $orders=GemTransaction::where('bot_id',$id)->where('status','processing')->whereNotNull('delivery_data->claim')->get();
            foreach($orders as $order) abort_unless(in_array($order->delivery_phase,['waiting_customer','waiting_consignment','waiting_login','ready'],true)
                || !empty($order->delivery_data['stock_refresh_pending_step_id']),409,'Stock correction waits until active receipts are reconciled.');
            $this->applySnapshot($bot,$d,'sync_correction',null,null);
            foreach($orders as $order) if(!empty($order->delivery_data['stock_refresh_pending_step_id'])) {
                $data=$order->delivery_data;unset($data['stock_refresh_pending_step_id']);$order->forceFill(['delivery_data'=>$data])->save();
            }
            return $bot->fresh('server');
        },3);
        ApiCache::clearGroups(['public:gembot','public:server-prices']);
        return $bot;
    }
    public function updateOrder(int $id,array $d): GemTransaction {
        $order=DB::transaction(function() use($id,$d) {
            // Starting delivery and completing it use the same bot-before-order lock order.
            $bot=GemBot::whereKey($d['bot_id'])->lockForUpdate()->firstOrFail();
            $order=GemTransaction::whereKey($id)->lockForUpdate()->firstOrFail();
            // Lost responses are acknowledged from the committed receipt and exact saved body.
            $starting=in_array($d['action'],['start','claim'],true);
            if(!$starting && isset($d['step_id'])) {
                $suffix=$d['action'];
                $previous=InventoryMovement::where('idempotency_key',"trading-v3:gem:{$id}:{$d['step_id']}:{$suffix}")->first();
                if($previous) {
                    abort_unless((int)$previous->bot_id===$bot->id,409,'Acknowledged bot differs from original.');
                    abort_unless(hash_equals((string)($previous->meta['payload_hash']??''),$this->digest($d)),409,'Acknowledged body differs from original.');
                    return $order;
                }
            }
            abort_unless((int)$bot->server_id===(int)$order->server_id,422,'Order and bot server_id differ.');
            if(in_array($d['action'],['timeout','refund','refund_pending'],true)) {
                abort_if($order->bot_id && (int)$order->bot_id!==$bot->id,409,'Order is assigned to another bot.');
                $timeouts=app(OrderTimeoutService::class);
                if($d['action']==='refund_pending') {
                    if(in_array($order->status,['completed','refunded'],true))return $order;
                    abort_unless($order->status==='pending',409,'Only pending orders can be refunded by the tool.');
                    abort_unless($timeouts->refundPendingLocked($order,$d['timeout_seconds']),409,'Pending refund deadline has not elapsed or delivery is already started.');
                    $this->workflow($bot,$order,$d);
                    return $order;
                } elseif($d['action']==='timeout') {
                    if(in_array($order->status,['completed','cancelled','refunded'],true))return $order;
                    abort_unless($order->status==='pending',409,'Only pending orders can time out.');
                    abort_unless($timeouts->cancelLocked($order,$d['timeout_minutes'],'gem',$d['refund_grace_minutes']??null),409,'Pending timeout has not elapsed.');
                } else {
                    if($order->status==='refunded')return $order;
                    abort_unless($order->status==='cancelled'&&$order->cancel_requested_at,409,'Only timeout-cancelled orders can be refunded.');
                }
                $timeouts->refundLocked($order,'gem');
                if($d['action']==='refund')abort_unless($order->refunded_at!==null,409,'Refund grace period has not elapsed.');
                $this->workflow($bot,$order,$d);
                return $order;
            }
            if($starting) {
                if(!in_array($order->status,['pending','processing'],true))return $order;
                abort_if($order->bot_id && (int)$order->bot_id!==$bot->id,409,'Order is assigned to another bot.');
                if($order->status==='processing') {
                    if(empty($order->delivery_data['claim'])) {
                        // V2 entered processing after handing the equipment to the customer.
                        // Adopt that meaning, never manufacture a second handover or stock debit.
                        abort_unless($order->delivery_method==='consignment',409,'Gift delivery needs its saved game result before it can resume.');
                        $data=$order->delivery_data??[];
                        $data['claim']=['character_name'=>$order->character_name,'gem_qty'=>(int)$order->gem_qty,'delivery_method'=>$order->delivery_method];
                        $data['legacy_resume']=['source'=>'v2','no_redelivery'=>true,'original_item'=>$order->item,
                            'original_phase'=>$order->delivery_phase,'adopted_at'=>now()->toIso8601String()];
                        $order->forceFill(['bot_id'=>$bot->id,'delivery_data'=>$data,'delivery_phase'=>'waiting_consignment',
                            'processing_started_at'=>$order->processing_started_at??now(),'updated_by'=>'app'])->save();
                    }
                    $terms=$order->delivery_data['claim'];
                    abort_unless(($terms['character_name']??null)===$order->character_name && ($terms['gem_qty']??null)===(int)$order->gem_qty
                        && ($terms['delivery_method']??null)===$order->delivery_method,409,'Order terms changed after delivery started.');
                    if(isset($d['character_id'])) {
                        abort_if($order->character_id && (int)$order->character_id!==$d['character_id'],409,'Recipient ID changed.');
                        if(!$order->character_id) {$order->character_id=$d['character_id'];$order->save();}
                    }
                    return $order;
                }
                abort_unless($bot->status && $bot->server?->status,409,'Bot is not available.');
                $available=GemStock::available($bot,$order->id);
                abort_unless($available['gems'] >= $order->gem_qty,409,'Gem inventory is reserved for other orders.');
                if($order->delivery_method==='gift_ticket') {
                    abort_unless($order->gem_qty>0 && $order->gem_qty%10===0,422,'Ticket orders require multiples of 10.');
                    abort_unless($bot->gift_ticket_qty!==null && $available['tickets'] >= $order->gem_qty/10,409,'Insufficient verified ticket stock.');
                } else abort_unless($available['items']>0,409,'Insufficient consignment stock.');
                $initialPhase=$d['phase']??'waiting_customer';
                abort_unless($initialPhase==='waiting_customer' || ($order->delivery_method==='gift_ticket' && $initialPhase==='gifting'),422,'Invalid initial delivery phase.');
                $order->forceFill(['bot_id'=>$bot->id,'status'=>'processing','character_id'=>$d['character_id']??null,
                    'delivery_data'=>array_replace($order->delivery_data??[],['claim'=>['character_name'=>$order->character_name,'gem_qty'=>(int)$order->gem_qty,'delivery_method'=>$order->delivery_method]]),
                    'delivery_phase'=>$initialPhase,'processing_started_at'=>now(),'updated_by'=>'app'])->save();
                return $order;
            }
            abort_unless((int)$order->bot_id===$bot->id && !empty($order->delivery_data['claim']),409,'Order bot or delivery checkpoint differs.');
            $claim=$order->delivery_data['claim']??[];
            abort_unless(($claim['character_name']??null)===$order->character_name && ($claim['gem_qty']??null)===(int)$order->gem_qty
                && ($claim['delivery_method']??null)===$order->delivery_method,409,'Order terms changed after claim; reconciliation is required.');
            if($d['action']==='release_unstarted') {
                $allowedPhases=$order->delivery_method==='gift_ticket'?['waiting_customer','gifting']:['waiting_customer','giving_item'];
                abort_unless($order->status==='processing'&&in_array($order->delivery_method,['consignment','gift_ticket'],true)
                    && in_array($order->delivery_phase,$allowedPhases,true)
                    && !$order->bot_gem_spent_qty&&!$order->delivered_gem_qty&&!$order->tickets_used_qty
                    && empty($order->delivery_data['legacy_resume']['no_redelivery'])
                    && empty($order->delivery_data['delivery_item']['verified'])&&!empty($d['evidence']['no_effect']),409,'Only a confirmed unapplied pre-delivery action can return to pending.');
                abort_if(InventoryMovement::where('transaction_type','gem_transaction')->where('transaction_id',$id)
                    ->where('asset_type','gem')->whereNotNull('idempotency_key')->exists(),409,'An acknowledged receipt must not be released.');
                $data=$order->delivery_data??[];
                $data['released_claim']=['step_id'=>$d['step_id'],'released_at'=>now()->toIso8601String()];
                unset($data['claim'],$data['delivery_item'],$data['listing_id']);
                $order->forceFill(['status'=>'pending','character_id'=>null,
                    'delivery_phase'=>null,'processing_started_at'=>null,'delivery_data'=>$data])->save();
                $this->workflow($bot,$order,$d);
                return $order;
            }
            if($d['action']==='phase') {
                $phaseKey=isset($d['step_id']) ? "trading-v3:gem:{$id}:{$d['step_id']}:phase" : null;
                if($order->status!=='processing')return $order;
                $legacyListing=($d['evidence']['kind']??null)==='legacy_listing';
                if($legacyListing)$this->adoptLegacyListing($order,$d);
                else abort_if(!empty($d['delivery_data']['delivery_item']['verified']),422,'Equipment is verified only by a confirmed trade receipt.');
                $previousPlan=$order->delivery_data['delivery_item']??null;
                $newPlan=$d['delivery_data']['delivery_item']??null;
                abort_if($previousPlan && $newPlan && $this->canonical($previousPlan)!==$this->canonical($newPlan),409,'The frozen equipment plan cannot be replaced.');
                if(isset($d['character_id'])) {
                    abort_if($order->character_id && (int)$order->character_id!==$d['character_id'],409,'Recipient ID changed.');
                    $order->character_id=$d['character_id'];
                }
                $order->delivery_phase=$d['phase'];
                $order->delivery_data=array_replace_recursive($order->delivery_data??[],$d['delivery_data']??[]);
                if(in_array($d['phase'],['giving_item','gifting','buying'],true)) {
                    $data=$order->delivery_data;unset($data['stock_refresh_pending_step_id']);$order->delivery_data=$data;
                }
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
            $spent=$d['gem_spent_qty'];$received=$d['delivered_gem_qty'];$tickets=$d['tickets_used_qty'];
            abort_if($order->bot_gem_spent_qty+$spent>$order->gem_qty,422,'Receipt exceeds order quantity.');
            if($order->delivery_method==='gift_ticket') {
                abort_unless($d['evidence']['kind']==='gift' && $spent>0 && $spent%10===0 && $received===$spent && $tickets===intdiv($spent,10),422,'Ticket receipt quantities do not match.');
                if(isset($d['evidence']['recipient_name'])||!$order->character_id) {
                    abort_unless(($d['evidence']['recipient_name']??null)===$claim['character_name']
                        && ($d['evidence']['gift_gem_qty']??null)===$spent && ($d['evidence']['gift_ticket_qty']??null)===$tickets,
                        422,'Fresh gift confirmation must identify the frozen recipient and quantities.');
                } else abort_unless(!isset($d['character_id'])||$d['character_id']===$order->character_id,422,'Gift recipient ID changed.');
            } else {
                $legacyProof=$order->delivery_data['legacy_resume']['listing']??null;
                if($d['evidence']['kind']==='consignment' && $legacyProof) {
                    abort_unless(($d['evidence']['recipient_name']??null)===$claim['character_name']
                        && ($d['evidence']['listing_id']??null)===($legacyProof['listing_id']??null),422,'Legacy purchase must identify the frozen seller and listing.');
                    abort_if(isset($d['delivery_data']['delivery_item']) && $this->canonical($d['delivery_data']['delivery_item'])!==$this->canonical($order->delivery_data['delivery_item']),409,'The frozen legacy equipment cannot be replaced.');
                    abort_if(isset($d['delivery_data']['listing_id']) && $d['delivery_data']['listing_id']!==$legacyProof['listing_id'],409,'The frozen legacy listing cannot be replaced.');
                } else abort_unless($order->character_id && (!isset($d['character_id']) || $d['character_id']===$order->character_id),422,'A verified recipient ID is required.');
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
            $itemDelta=match($d['evidence']['kind']){'trade'=>-1,'consignment'=>1,default=>0};
            if($d['completed']) abort_unless($order->bot_gem_spent_qty+$spent===(int)$order->gem_qty,422,'Order has not been fully delivered.');
            $pending=ReceiptSnapshotPolicy::gemPending($d,$order);
            $this->applyReceiptSnapshot($bot,$snap,$order,$key,
                ['gem_qty'=>-$spent,'gift_ticket_qty'=>-$tickets,'consign_item_qty'=>$itemDelta],
                ['payload_hash'=>$digest,'evidence'=>$d['evidence']],$pending);
            $order->bot_gem_spent_qty+=$spent;$order->delivered_gem_qty+=$received;$order->tickets_used_qty+=$tickets;
            $order->delivery_data=array_replace_recursive($order->delivery_data??[],$d['delivery_data']??[]);
            $deliveryData=$order->delivery_data;
            $deliveryData['last_step_id']=$d['step_id'];
            unset($deliveryData['stock_refresh_pending_step_id']);
            if($pending && !$d['completed']) $deliveryData['stock_refresh_pending_step_id']=$d['step_id'];
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
    private function adoptLegacyListing(GemTransaction $order,array $d): void {
        $data=$order->delivery_data??[];$proof=$d['evidence']??[];$plan=$d['delivery_data']['delivery_item']??[];
        $listing=$d['delivery_data']['listing_id']??null;
        abort_unless($order->delivery_method==='consignment' && !empty($data['legacy_resume']['no_redelivery'])
            && $d['phase']==='waiting_consignment' && isset($d['step_id']) && !empty($proof['confirmed']),422,'Only adopted V2 consignment can freeze an observed listing.');
        abort_unless(($proof['recipient_name']??null)===$order->character_name && is_int($listing) && $listing>=0
            && ($proof['listing_id']??null)===$listing && ($proof['buy_type']??null)===1 && ($proof['quantity']??null)===1
            && ($proof['gem_qty']??null)===(int)$order->gem_qty-(int)$order->bot_gem_spent_qty
            && !empty($plan['verified']) && (int)($plan['quantity']??0)===1 && !empty($plan['signature']),422,'Observed listing does not match the frozen seller, price and equipment.');
        $frozen=['listing_id'=>$listing,'recipient_name'=>$order->character_name,'gem_qty'=>$proof['gem_qty'],
            'buy_type'=>1,'quantity'=>1,'template_id'=>$plan['template_id'],'signature'=>$plan['signature']];
        $previous=$data['legacy_resume']['listing']??null;
        abort_if($previous && $this->canonical($previous)!==$this->canonical($frozen),409,'The frozen legacy listing cannot be replaced.');
        $data['legacy_resume']['listing']=$frozen;
        $order->delivery_data=$data;
    }
    private function workflow(GemBot $bot,GemTransaction $order,array $d): void {
        InventoryMovement::create(['server_id'=>$bot->server_id,'bot_id'=>$bot->id,'bot_type'=>'gem_bot','asset_type'=>'workflow',
            'movement_type'=>'phase','quantity_delta'=>0,'balance_before'=>0,'balance_after'=>0,'transaction_id'=>$order->id,
            'transaction_type'=>'gem_transaction','idempotency_key'=>"trading-v3:gem:{$order->id}:{$d['step_id']}:{$d['action']}",
            'source'=>'app','meta'=>['payload_hash'=>$this->digest($d),'action'=>$d['action']],'occurred_at'=>now()]);
    }
    private function applyReceiptSnapshot(GemBot $bot,array $s,GemTransaction $order,string $key,array $deltas,array $meta,bool $pending): void {
        $fields=['name','gem_qty','gift_ticket_qty','consign_item_qty'];$old=$bot->only($fields);
        $apply=!$pending && (int)$s['stock_version']>(int)$bot->stock_version;
        $watermark=$pending && (int)$s['stock_version']>(int)$bot->stock_version;
        $meta=[...$meta,'reported_snapshot'=>$s,'previous_stock_version'=>(int)$bot->stock_version,
            'snapshot_applied'=>$apply,'snapshot_watermark_advanced'=>$watermark,
            'snapshot_resolution'=>$pending?'snapshot_pending':($apply?'fresh_observation':'snapshot_already_current')];
        if($apply) $bot->forceFill([...array_intersect_key($s,array_flip($fields)),
            'stock_version'=>(int)$s['stock_version'],'updated_by'=>'app','last_synced_at'=>now()])->save();
        // A stock upload sent before this effect may still be in flight. Fence it out
        // without publishing the provisional quantities or claiming a fresh observation.
        elseif($watermark) $bot->forceFill(['stock_version'=>(int)$s['stock_version']])->save();
        foreach(['gem_qty'=>'gem','gift_ticket_qty'=>'gem_ticket','consign_item_qty'=>'consign_item'] as $field=>$asset) {
            $before=(int)($old[$field]??0);$after=(int)$bot->$field;$delta=$deltas[$field];
            // Keep the primary receipt marker even for equipment-only delivery and zero net changes.
            if($before===$after && $delta===0 && $field!=='gem_qty') continue;
            ReceiptInventoryLedger::record(['server_id'=>$bot->server_id,'bot_id'=>$bot->id,'bot_type'=>'gem_bot',
                'asset_type'=>$asset,'movement_type'=>'sale','transaction_id'=>$order->id,'transaction_type'=>'gem_transaction',
                'idempotency_key'=>$field==='gem_qty'?$key:$key.':'.$asset,'source'=>'order','meta'=>$meta,
                'note'=>'V3 confirmed game receipt quantity','occurred_at'=>now()],$before,$after,$delta);
        }
        if($apply) BotHistoryService::logUpdate('gem_bot',$bot,$old,$bot->only($fields),'app',
            category:'runtime',transactionId:$order->id,transactionType:'gem_transaction',note:'Inventory observed with confirmed receipt');
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
    private function canonical(array $value): array {
        // MySQL JSON can reorder object keys. Keep strict values/types, ignore key order.
        ksort($value);foreach($value as $key=>$item)if(is_array($item))$value[$key]=$this->canonical($item);
        return $value;
    }
    private function digest(array $value): string {
        return hash('sha256',json_encode($this->canonical($value),JSON_THROW_ON_ERROR));
    }
}

