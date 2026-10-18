<?php
namespace App\Services\TradingV3;

use App\Models\{Bot,GoldTransaction,InventoryMovement,Transaction,User};
use App\Services\{BotHistoryService,InventoryMovementService,TransactionService,UserRealtimeNotifier};
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;

class GoldTradingService {
    public const BAR_CAPACITY=999;
    public static function types(Bot $bot): array {
        return array_values(array_unique(array_filter(array_map('trim',explode(',',(string)$bot->type)))));
    }
    public static function auxiliary(Bot $bot): bool {
        return !$bot->isChecker() && (bool)array_intersect(self::types($bot),['selling_sub','import_sub']);
    }
    public static function remaining(GoldTransaction $order): array {
        $p=$order->delivery_data['progress']??[];
        return ['bars'=>max(0,(int)$order->gold_bar_qty-(int)($p['bars']??0)),
            'pure'=>max(0,(int)$order->pure_gold_qty-(int)($p['pure']??0))];
    }
    /** Acknowledged no-effect waits retain their quote, but do not occupy the game actor. */
    public static function settledStockWait(GoldTransaction $order): bool {
        $data=$order->delivery_data??[];$progress=$data['progress']??[];
        return $order->status==='processing' && $order->delivery_phase==='waiting_stock'
            && ($data['stock_wait_settled']??false)===true && self::hasCheckpoint($order)
            && !(int)($progress['bars']??0) && !(int)($progress['pure']??0)
            && empty($data['last_step_id']) && empty($data['stock_refresh_pending_step_id']);
    }
    private static function settledTransferWait(GoldTransaction $order): bool {
        return self::settledStockWait($order) || ($order->status==='processing'
            && $order->delivery_phase==='waiting_stock' && self::hasCheckpoint($order)
            && ($order->delivery_data['stock_transfer_settled']??false)===true
            && !empty($order->delivery_data['last_step_id'])
            && empty($order->delivery_data['stock_refresh_pending_step_id']));
    }
    public static function activeTransfers(?int $botId=null): \Illuminate\Support\Collection {
        return InventoryMovement::where('transaction_type','gold_transfer')->where('movement_type','reservation')
            ->where('meta->status','claimed')
            ->when($botId!==null,fn($q)=>$q->where(fn($q)=>$q->where('meta->from_bot_id',$botId)->orWhere('meta->to_bot_id',$botId)))
            ->get();
    }
    /** Read-only list projection; financial operations always query fresh reservations. */
    public static function prefetchReadStock(\Illuminate\Support\Collection $bots): \Illuminate\Support\Collection {
        if($bots->isEmpty()) return $bots;
        $byId=$bots->keyBy('id');$ids=$byId->keys()->all();$stock=[];
        foreach($byId as $id=>$bot) $stock[$id]=[
            'available'=>['bars'=>(int)$bot->gold_bar_qty,'pure'=>(int)$bot->gold_qty],
            'receive_room'=>self::BAR_CAPACITY-(int)$bot->gold_bar_qty,
        ];
        $orders=GoldTransaction::whereIn('bot_id',$ids)->where('status','processing')->whereIn('type',['order','import'])
            ->get(['id','bot_id','status','delivery_phase','delivery_data','type','server_id','character_name',
                'gold_bar_qty','pure_gold_qty','gold_qty','amount_vnd','price_at_transaction']);
        foreach($orders as $order) {
            if(self::settledStockWait($order)) continue;
            $remaining=self::remaining($order);$id=(int)$order->bot_id;
            if($order->type==='order') {
                $stock[$id]['available']['bars']-=$remaining['bars'];
                $stock[$id]['available']['pure']-=$remaining['pure'];
            } else $stock[$id]['receive_room']-=$remaining['bars'];
        }
        $transfers=InventoryMovement::where('transaction_type','gold_transfer')->where('movement_type','reservation')
            ->where('meta->status','claimed')
            ->where(fn($q)=>$q->whereIn('meta->from_bot_id',$ids)->orWhereIn('meta->to_bot_id',$ids))->get(['meta']);
        foreach($transfers as $transfer) {
            $meta=$transfer->meta;$from=(int)$meta['from_bot_id'];$to=(int)$meta['to_bot_id'];
            if(isset($stock[$from])) {
                $stock[$from]['available']['bars']-=(int)$meta['bars_qty'];
                $stock[$from]['available']['pure']-=(int)$meta['pure_gold_qty'];
            }
            if(isset($stock[$to])) $stock[$to]['receive_room']-=(int)$meta['bars_qty'];
        }
        foreach($bots as $bot) {
            $read=$stock[$bot->id];$read['available']=array_map(fn($qty)=>max(0,$qty),$read['available']);
            $read['receive_room']=max(0,$read['receive_room']);
            $bot->setRelation('tradingV3ReadStock',$read);
        }
        return $bots;
    }
    public static function available(Bot $bot,?int $exceptOrder=null,?string $exceptTransfer=null): array {
        $bars=(int)$bot->gold_bar_qty;$pure=(int)$bot->gold_qty;
        foreach(GoldTransaction::where('bot_id',$bot->id)->where('type','order')->where('status','processing')->get() as $o) {
            if($o->id===$exceptOrder || self::settledStockWait($o)) continue;
            $r=self::remaining($o);$bars-=$r['bars'];$pure-=$r['pure'];
        }
        foreach(self::activeTransfers($bot->id) as $m) {
            $d=$m->meta;
            if((int)$d['from_bot_id']===$bot->id && ($d['id']??null)!==$exceptTransfer) {$bars-=(int)$d['bars_qty'];$pure-=(int)$d['pure_gold_qty'];}
        }
        return ['bars'=>max(0,$bars),'pure'=>max(0,$pure)];
    }
    public static function receiveRoom(Bot $bot,?int $exceptOrder=null,?string $exceptTransfer=null): int {
        $room=self::BAR_CAPACITY-(int)$bot->gold_bar_qty;
        foreach(GoldTransaction::where('bot_id',$bot->id)->where('type','import')->where('status','processing')->get() as $o) {
            if($o->id!==$exceptOrder && !self::settledStockWait($o)) $room-=self::remaining($o)['bars'];
        }
        foreach(self::activeTransfers($bot->id) as $m) {
            $d=$m->meta;
            if((int)$d['to_bot_id']===$bot->id && ($d['id']??null)!==$exceptTransfer) $room-=(int)$d['bars_qty'];
        }
        return max(0,$room);
    }
    public static function hasActiveDelivery(Bot $bot): bool {
        return GoldTransaction::where('bot_id',$bot->id)->whereNotNull('delivery_data->claim')->where('status','processing')->exists()
            || self::activeTransfers($bot->id)->isNotEmpty();
    }
    private function assertEnabled(Bot $bot): void {
        abort_unless($bot->status && $bot->server?->status,409,'Bot or server is disabled.');
        $login=$bot->loginServer;
        abort_unless($login && trim((string)$login->ip)!=='' && (int)$login->port>0 && (int)$login->port<=65535,409,'A valid database login endpoint is required.');
    }
    public function updateBot(int $id,array $d): Bot {
        // Legacy presence/release requests do not change account or workflow state.
        if(in_array($d['action'],['lease','release'],true)) return Bot::with(['server','loginServer'])->findOrFail($id);
        $bot=DB::transaction(function() use($id,$d) {
            $bot=Bot::whereKey($id)->lockForUpdate()->firstOrFail();
            $orders=GoldTransaction::where('bot_id',$id)->where('status','processing')->get();
            $transfers=self::activeTransfers($id);
            abort_if($transfers->isNotEmpty(),409,'Unfinished gold transfers must be reconciled first.');
            foreach($orders as $order) abort_unless($this->hasCheckpoint($order)
                && (self::settledTransferWait($order) || !empty($order->delivery_data['stock_refresh_pending_step_id'])),409,'Stock correction requires a settled waiting-stock boundary.');
            $this->applySnapshot($bot,$d,'sync_correction',null,null);
            foreach($orders as $order) if(!empty($order->delivery_data['stock_refresh_pending_step_id'])) {
                $data=$order->delivery_data;unset($data['stock_refresh_pending_step_id']);$order->forceFill(['delivery_data'=>$data])->save();
            }
            return $bot->fresh(['server','loginServer']);
        },3);
        ApiCache::clearGroups(['public:bots']);return $bot;
    }
    /** Settle already-confirmed predecessors and claim the next order atomically. */
    public function updateOrderWithReceipts(int $id,array $d): array {
        $pending=$d['pending_receipts']??[];$pendingTransfers=$d['pending_transfers']??[];
        unset($d['pending_receipts'],$d['pending_transfers']);
        if(!$pending && !$pendingTransfers) return ['order'=>$this->updateOrder($id,$d),'receipts'=>[],'transfers'=>[]];
        abort_unless(in_array($d['action'],['start','claim'],true) && count($pending)<=16 && count($pendingTransfers)<=16,422,'Pending receipts are supported only with a bounded new order claim.');
        return DB::transaction(function()use($id,$d,$pending,$pendingTransfers){
            // Acquire every account before any workflow row, in the same order as transfer writes.
            $botIds=[(int)$d['bot_id']];
            foreach($pendingTransfers as $entry) {
                $payload=$entry['payload'];
                abort_unless($payload['action']==='receipt' && !isset($payload['pending_transfers']) && !isset($payload['pending_receipts'])
                    && in_array((int)$d['bot_id'],[(int)$payload['from_bot_id'],(int)$payload['to_bot_id']],true),422,'A predecessor transfer must be a receipt involving the current bot.');
                $botIds[]=(int)$payload['from_bot_id'];$botIds[]=(int)$payload['to_bot_id'];
            }
            $botIds=array_values(array_unique($botIds));sort($botIds);
            abort_unless(Bot::whereIn('id',$botIds)->orderBy('id')->lockForUpdate()->get()->count()===count($botIds),404,'A bundled transfer account was not found.');
            $transfers=[];
            foreach($pendingTransfers as $entry) {
                $payload=$entry['payload'];$transferId=(string)$entry['transfer_id'];
                $transfer=$this->updateTransfer($transferId,$payload);
                abort_unless($transfer['status']==='completed',409,'Predecessor transfer receipt has not completed.');
                $transfers[]=['transfer_id'=>$transferId,'step_id'=>$payload['step_id'],'data'=>$transfer];
            }
            $receipts=[];
            foreach($pending as $entry) {
                $payload=$entry['payload'];$previousId=(int)$entry['order_id'];
                abort_unless($previousId!==$id && (int)$payload['bot_id']===(int)$d['bot_id']
                    && $payload['action']==='receipt' && !empty($payload['completed']) && !isset($payload['pending_receipts']),
                    422,'A predecessor must be a terminal receipt for another order on the same bot.');
                $previous=$this->updateOrder($previousId,$payload);
                abort_unless($previous->status==='completed',409,'Predecessor receipt has not completed its order.');
                $receipts[]=['order_id'=>$previousId,'step_id'=>$payload['step_id'],'order'=>$previous];
            }
            // Only acknowledged zero-effect waits may share the bot with this new order.
            return ['order'=>$this->updateOrder($id,$d),'receipts'=>$receipts,'transfers'=>$transfers];
        },3);
    }
    public function updateOrder(int $id,array $d): GoldTransaction {
        $order=DB::transaction(function() use($id,$d) {
            // Transfers and order writes always lock accounts in ascending ID order before workflow rows.
            $bot=Bot::whereKey($d['bot_id'])->lockForUpdate()->firstOrFail();
            $order=GoldTransaction::whereKey($id)->lockForUpdate()->firstOrFail();
            $suffix=match($d['action']){'receipt'=>'receipt','refund_pending'=>'refund_pending','release_unstarted'=>'release_unstarted',default=>'phase'};
            $key=isset($d['step_id']) ? "trading-v3:gold:{$id}:{$d['step_id']}:{$suffix}" : null;
            $starting=in_array($d['action'],['start','claim'],true);
            if(!$starting && $key && ($prior=InventoryMovement::where('idempotency_key',$key)->first())) {
                if(in_array($d['action'],['refund_pending','release_unstarted'],true))abort_unless((int)$prior->bot_id===$bot->id,409,'Acknowledged bot differs from original.');
                else $this->assertOrderCheckpoint($order,$bot,$d);
                abort_unless(hash_equals((string)($prior->meta['payload_hash']??''),$this->digest($d)),409,'Acknowledged body differs from original.');
                return $order;
            }
            abort_unless((int)$bot->server_id===(int)$order->server_id,422,'Order and bot server_id differ.');
            if($d['action']==='refund_pending') {
                abort_if($bot->isChecker(),422,'Checker bots do not process customer orders.');
                abort_unless($order->type===GoldTransaction::TYPE_ORDER,422,'Only pending gold purchase orders can be refunded by the tool.');
                abort_if($order->bot_id && (int)$order->bot_id!==$bot->id,409,'Order is assigned to another bot.');
                if(!$order->bot_id)abort_unless(in_array('selling_main',self::types($bot),true),422,'An unassigned gold purchase order requires a selling bot.');
                if($order->refunded_at!==null)return $order;
                abort_unless($order->status==='pending',409,'Only pending gold purchase orders can be refunded by the tool.');
                abort_if(InventoryMovement::where('transaction_type','gold_transaction')->where('transaction_id',$id)
                    ->where('asset_type','pure_gold')->whereNotNull('idempotency_key')->exists(),409,'An acknowledged gold delivery cannot be refunded.');
                abort_unless(app(OrderTimeoutService::class)->refundPendingLocked($order,$d['timeout_seconds'],'gold'),409,'Pending refund deadline has not elapsed or delivery is already started.');
                $this->workflow($bot,$key,'phase',$id,'gold_transaction',['payload_hash'=>$this->digest($d),'action'=>'refund_pending']);
                return $order;
            }
            if($starting) {
                if(!in_array($order->status,['pending','processing'],true))return $order;
                abort_if($bot->isChecker(),422,'Checker bots do not process customer orders.');
                abort_if($order->bot_id && (int)$order->bot_id!==$bot->id,409,'Order is assigned to another bot.');
                if($order->status==='processing') {
                    abort_unless($this->hasCheckpoint($order),409,'legacy_checkpoint_missing: this order needs its original delivery checkpoint.');
                    if(isset($d['character_id'])) {
                        abort_if($order->character_id && (int)$order->character_id!==$d['character_id'],409,'Customer character ID changed.');
                        if(!$order->character_id) {$order->character_id=$d['character_id'];$order->save();}
                    }
                    if(self::settledStockWait($order)) {
                        $this->assertCustomerBoundary($bot,$id);
                        $this->assertCustomerRole($bot,$order);
                        if($this->canFulfil($bot,$order)) {
                            $data=$order->delivery_data;unset($data['stock_wait_settled']);
                            $order->forceFill(['delivery_phase'=>'waiting_customer','delivery_data'=>$data])->save();
                        }
                    }
                    return $order;
                }
                $this->assertEnabled($bot);
                $role=$order->type==='order'?'selling_main':'import_main';
                abort_unless(in_array($role,self::types($bot),true) && in_array($order->type,['order','import'],true),422,'Bot role does not match order direction.');
                $this->assertCustomerBoundary($bot,$id);
                $terms=$this->terms($order);
                abort_unless($terms['bars']>=0 && $terms['pure']>=0 && ($terms['bars']>0 || $terms['pure']>0)
                    && $terms['bars']<=intdiv(PHP_INT_MAX-$terms['pure'],InventoryMovementService::GOLD_PER_BAR)
                    && (int)$order->gold_qty===$terms['bars']*InventoryMovementService::GOLD_PER_BAR+$terms['pure'],422,'Order component quantities do not match converted gold.');
                abort_unless($terms['amount_vnd']>0 && $terms['price_at_transaction']>0,422,'Invalid frozen order amount or exchange rate.');
                $a=self::available($bot,$id);
                $nextBars=min($terms['bars'],self::BAR_CAPACITY);$nextPure=min($terms['pure'],2147483647);
                $waiting=$order->type==='order' ? ($a['bars']<$nextBars || $a['pure']<$nextPure) : (self::receiveRoom($bot,$id)<$nextBars);
                abort_unless(!isset($d['phase']) || $d['phase']==='waiting_customer',422,'Invalid initial delivery phase.');
                $order->forceFill(['bot_id'=>$bot->id,'status'=>'processing','character_id'=>$d['character_id']??null,'delivery_phase'=>$waiting?'waiting_stock':'waiting_customer',
                    'delivery_data'=>['claim'=>$terms,'progress'=>['bars'=>0,'pure'=>0],...($waiting?['stock_wait_settled'=>true]:[])],
                    'processing_started_at'=>now(),'updated_by'=>'app'])->save();
                return $order;
            }
            $this->assertOrderCheckpoint($order,$bot,$d);
            if($d['action']==='phase'&&$order->status!=='processing')return $order;
            abort_unless($order->status==='processing',409,'Order is terminal.');
            if($d['action']==='release_unstarted') {
                $progress=$order->delivery_data['progress']??[];
                abort_unless(in_array($order->type,[GoldTransaction::TYPE_ORDER,GoldTransaction::TYPE_IMPORT],true)
                    && in_array($order->delivery_phase,['waiting_customer','trading','waiting_login'],true)
                    && !(int)($progress['bars']??0) && !(int)($progress['pure']??0)
                    && empty($order->delivery_data['last_step_id']) && !empty($d['evidence']['no_effect']),409,'Only a confirmed unapplied pre-delivery action can return to pending.');
                abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'Unfinished gold transfers must be reconciled first.');
                abort_if(InventoryMovement::where('transaction_type','gold_transaction')->where('transaction_id',$id)
                    ->where('asset_type','pure_gold')->whereNotNull('idempotency_key')->exists(),409,'An acknowledged receipt must not be released.');
                $data=$order->delivery_data??[];
                $data['released_claim']=['step_id'=>$d['step_id'],'released_at'=>now()->toIso8601String()];
                unset($data['claim']);
                $order->forceFill(['status'=>'pending','character_id'=>null,
                    'delivery_phase'=>null,'processing_started_at'=>null,'delivery_data'=>$data])->save();
                $this->workflow($bot,$key,'phase',$id,'gold_transaction',['payload_hash'=>$this->digest($d),'action'=>'release_unstarted']);
                return $order;
            }
            if(isset($d['character_id'])) {
                abort_if($order->character_id && (int)$order->character_id!==$d['character_id'],409,'Customer character ID changed.');
                $order->character_id=$d['character_id'];
            }
            if($d['action']==='phase') {
                abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'A reserved transfer must be settled first.');
                $data=$order->delivery_data;
                if($d['phase']==='waiting_stock') {
                    $progress=$data['progress']??[];
                    abort_unless(empty($data['stock_refresh_pending_step_id']) && !empty($d['evidence']['confirmed'])
                        && !empty($d['evidence']['no_effect']),409,'Stock waiting requires a confirmed no-effect boundary.');
                    if(!(int)($progress['bars']??0) && !(int)($progress['pure']??0) && empty($data['last_step_id'])) {
                        abort_if(InventoryMovement::where('transaction_type','gold_transaction')->where('transaction_id',$id)
                            ->where('asset_type','pure_gold')->whereNotNull('idempotency_key')->exists(),409,'An acknowledged receipt must not be parked.');
                        $data['stock_wait_settled']=true;unset($data['stock_transfer_settled']);
                    } else {
                        // A settled partial delivery may refill, but retains its actor and reservation.
                        abort_unless(!empty($data['last_step_id']),409,'Partial delivery requires its acknowledged receipt.');
                        $data['stock_transfer_settled']=true;unset($data['stock_wait_settled']);
                    }
                }
                if(in_array($d['phase'],['trading','waiting_customer'],true)) {
                    $this->assertCustomerRole($bot,$order);
                    $this->assertCustomerBoundary($bot,$id);
                    if(self::settledStockWait($order)) abort_unless($this->canFulfil($bot,$order),409,'Waiting order still lacks available stock or account room.');
                    unset($data['stock_wait_settled'],$data['stock_transfer_settled']);
                }
                $order->delivery_phase=$d['phase'];
                if($d['phase']==='trading') unset($data['stock_refresh_pending_step_id']);
                $order->delivery_data=$data;
                $order->save();
                $this->workflow($bot,$key,'phase',$id,'gold_transaction',['payload_hash'=>$this->digest($d)]);
                return $order;
            }
            abort_unless($order->character_id,422,'A verified customer character ID is required.');
            abort_if(self::settledStockWait($order),409,'A parked order must resume before a new delivery receipt.');
            abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'Receipt cannot overlap an unsettled transfer.');
            $bars=(int)$d['bars_qty'];$pure=(int)$d['pure_gold_qty'];$r=self::remaining($order);
            abort_unless(($bars>0 || $pure>0) && $bars<=$r['bars'] && $pure<=$r['pure'],422,'Receipt exceeds remaining order quantities.');
            $kind=$order->type==='import'?'import':'sale';
            abort_unless($d['evidence']['kind']===$kind,422,'Receipt direction differs from order.');
            $sign=$kind==='import'?1:-1;$snap=$d['bot_snapshot'];
            $fully=$bars===$r['bars'] && $pure===$r['pure'];
            abort_unless((bool)$d['completed']===$fully,422,'Completion flag does not match remaining order quantities.');
            $pending=ReceiptSnapshotPolicy::goldPending($d,$order);
            $this->applyReceiptSnapshot($bot,$snap,$kind,$order,$key,$sign*$bars,$sign*$pure,
                ['payload_hash'=>$this->digest($d),'evidence'=>$d['evidence'],'bars_qty'=>$bars,'pure_gold_qty'=>$pure],$pending);
            $data=$order->delivery_data;$data['progress']['bars']+=$bars;$data['progress']['pure']+=$pure;$data['last_step_id']=$d['step_id'];
            unset($data['stock_refresh_pending_step_id'],$data['stock_wait_settled'],$data['stock_transfer_settled']);
            if($pending && !$fully) $data['stock_refresh_pending_step_id']=$d['step_id'];
            $order->delivery_data=$data;$order->delivery_phase=$fully?'completed':'waiting_customer';
            if($fully) {
                if($kind==='import') $this->creditImport($order);
                $order->status='completed';$order->completed_at=now();
            }
            $order->updated_by='app';$order->last_synced_at=now();$order->save();
            DB::afterCommit(fn()=>app(UserRealtimeNotifier::class)->orderStatus((int)$order->user_id,'gold',$order->id,$order->status,$bot->id));
            return $order;
        },3);
        ApiCache::clearGroups(['public:bots']);return $order->fresh();
    }
    private static function terms(GoldTransaction $o): array {
        return ['type'=>$o->type,'server_id'=>(int)$o->server_id,'character_name'=>$o->character_name,
            'bars'=>(int)$o->gold_bar_qty,'pure'=>(int)$o->pure_gold_qty,'gold_qty'=>(int)$o->gold_qty,
            'amount_vnd'=>(int)$o->amount_vnd,'price_at_transaction'=>(int)$o->price_at_transaction];
    }
    private static function hasCheckpoint(GoldTransaction $o): bool {
        $claim=$o->delivery_data['claim']??null;
        return is_array($claim) && self::canonical($claim)===self::canonical(self::terms($o));
    }
    private function assertCustomerRole(Bot $bot,GoldTransaction $order): void {
        abort_if($bot->isChecker(),422,'Checker bots do not process customer orders.');
        $this->assertEnabled($bot);$role=$order->type==='order'?'selling_main':'import_main';
        abort_unless(in_array($role,self::types($bot),true) && in_array($order->type,['order','import'],true),422,'Bot role does not match order direction.');
    }
    private function assertCustomerBoundary(Bot $bot,int $exceptOrder): void {
        abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'Bot is busy with a reserved gold transfer.');
        foreach(GoldTransaction::where('bot_id',$bot->id)->where('status','processing')->where('id','!=',$exceptOrder)->get() as $other)
            abort_unless(self::settledStockWait($other),409,'Bot is busy with another gold operation.');
    }
    private function canFulfil(Bot $bot,GoldTransaction $order): bool {
        $remaining=self::remaining($order);$available=self::available($bot,$order->id);
        $bars=min($remaining['bars'],self::BAR_CAPACITY);$pure=min($remaining['pure'],2147483647);
        return $order->type==='order'
            ? $available['bars']>=$bars && $available['pure']>=$pure
            : self::receiveRoom($bot,$order->id)>=$bars;
    }
    private function assertOrderCheckpoint(GoldTransaction $o,Bot $b,array $d): void {
        abort_unless($this->hasCheckpoint($o) && (int)$o->bot_id===$b->id,409,'Original order bot or delivery checkpoint differs.');
    }
    private function creditImport(GoldTransaction $order): void {
        $key='gold-import-credit:'.$order->id;
        abort_if(Transaction::withoutUserOwnedScope()->where('idempotency_key',$key)->exists(),409,'Import credit exists before final receipt.');
        $user=User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();$amount=(int)$order->amount_vnd;$before=(int)$user->balance;
        abort_unless($amount>0 && $before<=TransactionService::MAX_BALANCE-$amount,422,'Import credit would exceed wallet limit.');
        $user->forceFill(['balance'=>$before+$amount])->save();
        TransactionService::log(userId:$user->id,type:'Nhập Vàng',amount:$amount,description:'Cộng tiền nhập vàng '.$user->username,
            related:$order,relatedId:$order->id,oldBalance:$before,newBalance:$before+$amount,idempotencyKey:$key,
            metadata:['source'=>'trading_v3','gold_transaction_id'=>$order->id]);
    }
    public function transferList(?string $worker=null): array {
        return self::activeTransfers()->map(fn($m)=>$this->transferResponse($m->meta))->values()->all();
    }
    public function updateTransfer(string $id,array $d): array {
        $d['pure_gold_qty']??=0;
        $result=DB::transaction(function() use($id,$d) {
            $ids=[(int)$d['from_bot_id'],(int)$d['to_bot_id']];sort($ids);
            $locked=Bot::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless(count($locked)===2,404,'Transfer account was not found.');
            $from=$locked[$d['from_bot_id']];$to=$locked[$d['to_bot_id']];
            $key='trading-v3:gold-transfer:'.$id;
            $row=InventoryMovement::where('idempotency_key',$key)->lockForUpdate()->first();
            $terms=['id'=>$id,'from_bot_id'=>$from->id,'to_bot_id'=>$to->id,
                'server_id'=>(int)$from->server_id,'bars_qty'=>(int)$d['bars_qty'],'pure_gold_qty'=>(int)$d['pure_gold_qty']];
            if($row) {
                foreach($terms as $field=>$value)abort_unless(($row->meta[$field]??null)===$value,409,'Transfer UUID was already used for different terms.');
                if($d['action']==='claim') return $row->meta;
                if(($row->meta['status']??null)!=='claimed') {
                    abort_unless(($row->meta['last_step_id']??null)===$d['step_id'] && ($row->meta['last_action']??null)===$d['action']
                        && hash_equals((string)($row->meta['payload_hash']??''),$this->digest($d)),409,'Transfer is terminal or acknowledged body differs.');
                    return $row->meta;
                }
            } else abort_unless($d['action']==='claim',404,'Transfer reservation was not found.');
            if($d['action']==='claim') {
                abort_if($from->isChecker() || $to->isChecker(),422,'Checker bots do not transfer inventory.');
                $this->assertEnabled($from);$this->assertEnabled($to);
                abort_unless((int)$from->server_id===(int)$to->server_id,422,'Transfer business servers differ.');
                $storage=in_array('import_main',self::types($from),true) && self::auxiliary($to);
                $refill=self::auxiliary($from) && in_array('selling_main',self::types($to),true);
                abort_unless($storage || $refill,422,'Transfer direction is not importer-to-storage or storage-to-seller.');
                $this->assertTransferBoundary($from);$this->assertTransferBoundary($to);
                $a=self::available($from,null,$id);
                abort_unless(($terms['bars_qty']>0 || $terms['pure_gold_qty']>0) && $a['bars']>=$terms['bars_qty'] && $a['pure']>=$terms['pure_gold_qty'],409,'Transfer source has insufficient unreserved stock.');
                abort_unless(self::receiveRoom($to,null,$id)>=$terms['bars_qty'],409,'Transfer receiver lacks reserved account room.');
                $meta=[...$terms,'status'=>'claimed','direction'=>$storage?'storage':'refill',
                    'from_before'=>['gold_qty'=>(int)$from->gold_qty,'gold_bar_qty'=>(int)$from->gold_bar_qty,'stock_version'=>(int)$from->stock_version],
                    'to_before'=>['gold_qty'=>(int)$to->gold_qty,'gold_bar_qty'=>(int)$to->gold_bar_qty,'stock_version'=>(int)$to->stock_version],
                    'created_at'=>now()->toIso8601String()];
                $this->workflow($from,$key,'reservation',null,'gold_transfer',$meta);return $meta;
            }
            $meta=$row->meta;
            if($d['action']==='cancel') {
                abort_unless($d['evidence']['unchanged']??false,422,'Cancellation requires a confirmed unchanged game state.');
                foreach(['from_before'=>$from,'to_before'=>$to] as $prefix=>$b) abort_unless((int)$b->gold_bar_qty===$meta[$prefix]['gold_bar_qty']
                    && (!$meta['pure_gold_qty'] || (int)$b->gold_qty===$meta[$prefix]['gold_qty'])
                    && (int)$b->stock_version===$meta[$prefix]['stock_version'],409,'Reserved stock changed; reconcile transfer receipt.');
                $meta['status']='cancelled';
            } else {
                $bars=$meta['bars_qty'];$pure=$meta['pure_gold_qty'];
                $pending=ReceiptSnapshotPolicy::goldPending($d);
                foreach([[$from,$d['from_snapshot'],-1],[$to,$d['to_snapshot'],1]] as [$b,$snap,$sign]) {
                    $this->applyReceiptSnapshot($b,$snap,$sign<0?'transfer_out':'transfer_in',null,$key.($sign<0?':out':':in'),$sign*$bars,$sign*$pure,
                        ['transfer_id'=>$id,'payload_hash'=>$this->digest($d),'evidence'=>$d['evidence']],$pending);
                }
                $meta['status']='completed';
            }
            $meta['last_step_id']=$d['step_id'];$meta['last_action']=$d['action'];$meta['payload_hash']=$this->digest($d);$meta['completed_at']=now()->toIso8601String();
            $row->update(['meta'=>$meta]);return $meta;
        },3);
        ApiCache::clearGroups(['public:bots']);return $this->transferResponse($result);
    }
    private function transferResponse(array $data): array {
        return array_diff_key($data,array_flip(['processing_owner','processing_token']));
    }
    private function assertTransferBoundary(Bot $bot): void {
        abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'Bot already has a reserved transfer.');
        foreach(GoldTransaction::where('bot_id',$bot->id)->where('status','processing')->get() as $order)
            abort_unless(self::settledTransferWait($order),409,'Order must have a confirmed no-effect wait before an internal transfer.');
    }
    private function applyReceiptSnapshot(Bot $bot,array $s,string $reason,?GoldTransaction $order,string $key,int $barsDelta,int $pureDelta,array $meta,bool $pending): void {
        abort_unless((int)$s['gold_bar_qty']>=0 && (int)$s['gold_bar_qty']<=self::BAR_CAPACITY && (int)$s['gold_qty']>=0,422,'Invalid bag account inventory.');
        $fields=['gold_qty','gold_bar_qty','name'];$old=$bot->only($fields);
        $apply=!$pending && (int)$s['stock_version']>(int)$bot->stock_version;
        $watermark=$pending && (int)$s['stock_version']>(int)$bot->stock_version;
        $meta=[...$meta,'reported_snapshot'=>$s,'previous_stock_version'=>(int)$bot->stock_version,
            'snapshot_applied'=>$apply,'snapshot_watermark_advanced'=>$watermark,
            'snapshot_resolution'=>$pending?'snapshot_pending':($apply?'fresh_observation':'snapshot_already_current')];
        if($apply) $bot->forceFill([...array_intersect_key($s,array_flip($fields)),
            'stock_version'=>(int)$s['stock_version'],'updated_by'=>'app','last_synced_at'=>now()])->save();
        // Keep late pre-effect uploads below the receipt's version barrier, including
        // each transfer endpoint, while leaving unobserved inventory quantities alone.
        elseif($watermark) $bot->forceFill(['stock_version'=>(int)$s['stock_version']])->save();
        $before=InventoryMovementService::convertedGold((int)$old['gold_qty'],(int)$old['gold_bar_qty']);
        $after=InventoryMovementService::convertedGold((int)$bot->gold_qty,(int)$bot->gold_bar_qty);
        $delta=$pureDelta+$barsDelta*InventoryMovementService::GOLD_PER_BAR;
        $barsCorrection=(int)$bot->gold_bar_qty-(int)$old['gold_bar_qty']-$barsDelta;
        $pureCorrection=(int)$bot->gold_qty-(int)$old['gold_qty']-$pureDelta;
        ReceiptInventoryLedger::record(['server_id'=>$bot->server_id,'bot_id'=>$bot->id,'bot_type'=>'bot','asset_type'=>'pure_gold',
            'movement_type'=>$reason,'transaction_id'=>$order?->id,'transaction_type'=>$order?'gold_transaction':'gold_transfer',
            'idempotency_key'=>$key,'source'=>$order?'order':'app','meta'=>[...$meta,'gold_per_bar'=>InventoryMovementService::GOLD_PER_BAR,
                'operation_bars_delta'=>$barsDelta,'operation_pure_delta'=>$pureDelta,
                'reconciliation_bars_delta'=>$barsCorrection,'reconciliation_pure_delta'=>$pureCorrection,
                'before_gold_qty'=>(int)$old['gold_qty'],'before_gold_bar_qty'=>(int)$old['gold_bar_qty'],
                'after_gold_qty'=>(int)$bot->gold_qty,'after_gold_bar_qty'=>(int)$bot->gold_bar_qty],
            'note'=>'V3 confirmed gold operation quantity','occurred_at'=>now()],$before,$after,$delta,$barsCorrection!==0 || $pureCorrection!==0);
        if($apply) BotHistoryService::logUpdate('bot',$bot,$old,$bot->only($fields),'app',category:'runtime',
            transactionId:$order?->id,transactionType:$order?'gold_transaction':null,note:'Inventory observed with confirmed receipt');
    }
    private function applySnapshot(Bot $bot,array $s,string $reason,?GoldTransaction $order,?string $key,array $meta=[]): void {
        abort_unless((int)$s['stock_version']>(int)$bot->stock_version,409,'Stock snapshot is stale.');
        abort_unless((int)$s['gold_bar_qty']>=0 && (int)$s['gold_bar_qty']<=self::BAR_CAPACITY && (int)$s['gold_qty']>=0,422,'Invalid bag account inventory.');
        $fields=['gold_qty','gold_bar_qty','name'];$old=$bot->only($fields);
        $before=InventoryMovementService::convertedGold((int)$bot->gold_qty,(int)$bot->gold_bar_qty);
        $bot->forceFill([...array_intersect_key($s,array_flip($fields)),'stock_version'=>(int)$s['stock_version'],'updated_by'=>'app','last_synced_at'=>now()])->save();
        $after=InventoryMovementService::convertedGold((int)$bot->gold_qty,(int)$bot->gold_bar_qty);
        InventoryMovement::create(['server_id'=>$bot->server_id,'bot_id'=>$bot->id,'bot_type'=>'bot','asset_type'=>'pure_gold',
            'movement_type'=>$reason,'quantity_delta'=>$after-$before,'balance_before'=>$before,'balance_after'=>$after,
            'transaction_id'=>$order?->id,'transaction_type'=>$order?'gold_transaction':($reason==='transfer_in' || $reason==='transfer_out'?'gold_transfer':null),
            'idempotency_key'=>$key,'source'=>$order?'order':'app','meta'=>[...$meta,'gold_per_bar'=>37000000,
                'before_gold_qty'=>(int)$old['gold_qty'],'before_gold_bar_qty'=>(int)$old['gold_bar_qty'],
                'after_gold_qty'=>(int)$bot->gold_qty,'after_gold_bar_qty'=>(int)$bot->gold_bar_qty],
            'note'=>'Trading v3 confirmed gold inventory','occurred_at'=>now()]);
        BotHistoryService::logUpdate('bot',$bot,$old,$bot->only($fields),$order?'order':'app',category:'runtime',
            transactionId:$order?->id,transactionType:$order?'gold_transaction':null,note:'Trading v3 gold stock update');
    }
    private function workflow(Bot $bot,string $key,string $movement,?int $transaction,string $type,array $meta): void {
        InventoryMovement::create(['server_id'=>$bot->server_id,'bot_id'=>$bot->id,'bot_type'=>'bot','asset_type'=>'workflow',
            'movement_type'=>$movement,'quantity_delta'=>0,'balance_before'=>0,'balance_after'=>0,'transaction_id'=>$transaction,
            'transaction_type'=>$type,'idempotency_key'=>$key,'source'=>'app','meta'=>$meta,'occurred_at'=>now()]);
    }
    private static function canonical(array $value): array {
        // MySQL JSON can reorder object keys. Keep strict values/types, ignore key order.
        ksort($value);foreach($value as $key=>$item)if(is_array($item))$value[$key]=self::canonical($item);
        return $value;
    }
    private function digest(array $d): string {
        return hash('sha256',json_encode($this->canonical($d),JSON_THROW_ON_ERROR));
    }
}
