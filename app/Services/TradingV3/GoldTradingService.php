<?php
namespace App\Services\TradingV3;

use App\Models\{Bot,GoldTransaction,InventoryMovement,Transaction,User};
use App\Services\{BotHistoryService,InventoryMovementService,TransactionService,UserRealtimeNotifier};
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoldTradingService {
    public const BAR_CAPACITY=999;
    public static function types(Bot $bot): array {
        return array_values(array_unique(array_filter(array_map('trim',explode(',',(string)$bot->type)))));
    }
    public static function auxiliary(Bot $bot): bool {
        return (bool)array_intersect(self::types($bot),['selling_sub','import_sub']);
    }
    public static function remaining(GoldTransaction $order): array {
        $p=$order->delivery_data['progress']??[];
        return ['bars'=>max(0,(int)$order->gold_bar_qty-(int)($p['bars']??0)),
            'pure'=>max(0,(int)$order->pure_gold_qty-(int)($p['pure']??0))];
    }
    public static function activeTransfers(?int $botId=null): \Illuminate\Support\Collection {
        return InventoryMovement::where('transaction_type','gold_transfer')->where('movement_type','reservation')
            ->where('meta->status','claimed')
            ->when($botId!==null,fn($q)=>$q->where(fn($q)=>$q->where('meta->from_bot_id',$botId)->orWhere('meta->to_bot_id',$botId)))
            ->get();
    }
    public static function available(Bot $bot,?int $exceptOrder=null,?string $exceptTransfer=null): array {
        $bars=(int)$bot->gold_bar_qty;$pure=(int)$bot->gold_qty;
        foreach(GoldTransaction::where('bot_id',$bot->id)->where('type','order')->where('status','processing')->get() as $o) {
            if($o->id===$exceptOrder) continue;
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
            if($o->id!==$exceptOrder) $room-=self::remaining($o)['bars'];
        }
        foreach(self::activeTransfers($bot->id) as $m) {
            $d=$m->meta;
            if((int)$d['to_bot_id']===$bot->id && ($d['id']??null)!==$exceptTransfer) $room-=(int)$d['bars_qty'];
        }
        return max(0,$room);
    }
    public static function ownedByV3(Bot $bot): bool {
        return ($bot->v3_worker_id && $bot->v3_lease_until?->isFuture())
            || GoldTransaction::where('bot_id',$bot->id)->where('processing_version',3)->where('status','processing')->exists()
            || self::activeTransfers($bot->id)->isNotEmpty();
    }
    private function assertOwner(Bot $bot,string $worker): void {
        abort_unless($bot->v3_worker_id===$worker,409,'Bot belongs to another worker.');
    }
    private function assertEnabled(Bot $bot): void {
        abort_unless($bot->status && $bot->server?->status,409,'Bot or server is disabled.');
        $login=$bot->loginServer;
        abort_unless($login && trim((string)$login->ip)!=='' && (int)$login->port>0 && (int)$login->port<=65535,409,'A valid database login endpoint is required.');
    }
    private function assertFinancialLease(Bot $bot,string $worker): void {
        $this->assertOwner($bot,$worker);$this->assertEnabled($bot);
        abort_unless($bot->v3_lease_until?->isFuture(),409,'Bot lease has expired.');
    }
    public function updateBot(int $id,array $d): Bot {
        $bot=DB::transaction(function() use($id,$d) {
            $bot=Bot::whereKey($id)->lockForUpdate()->firstOrFail();
            $orders=GoldTransaction::where('bot_id',$id)->where('status','processing')->get();
            $transfers=self::activeTransfers($id);
            if($d['action']==='lease') {
                $this->assertEnabled($bot);
                abort_if($bot->v3_worker_id && $bot->v3_worker_id!==$d['worker_id'] && $bot->v3_lease_until?->isFuture(),409,'Bot is leased to another worker.');
                foreach($orders as $order) {
                    abort_unless($order->processing_version===3 && $order->processing_owner===$d['worker_id'],409,'An unfinished order retains its worker.');
                }
                foreach($transfers as $t) abort_unless(($t->meta['processing_owner']??null)===$d['worker_id'],409,'An unfinished transfer retains its worker.');
                $bot->forceFill(['v3_worker_id'=>$d['worker_id'],'v3_lease_until'=>now()->addSeconds(config('trading_v3.lease_seconds'))])->save();
            } else {
                $this->assertOwner($bot,$d['worker_id']);
                abort_if($transfers->isNotEmpty(),409,'Unfinished gold transfers must be reconciled first.');
                if($d['action']==='release') {
                    abort_if($orders->isNotEmpty(),409,'Unfinished orders must retain their worker.');
                    $bot->forceFill(['v3_worker_id'=>null,'v3_lease_until'=>null])->save();
                } else {
                    foreach($orders as $order) abort_unless($order->processing_version===3 && $order->processing_owner===$d['worker_id']
                        && $order->delivery_phase==='waiting_stock',409,'Stock correction requires a settled waiting-stock boundary.');
                    $this->applySnapshot($bot,$d,'sync_correction',null,null);
                }
            }
            return $bot->fresh(['server','loginServer']);
        },3);
        ApiCache::clearGroups(['public:bots']);return $bot;
    }
    public function updateOrder(int $id,array $d): GoldTransaction {
        $order=DB::transaction(function() use($id,$d) {
            // Transfers and order writes always lock accounts in ascending ID order before workflow rows.
            $bot=Bot::whereKey($d['bot_id'])->lockForUpdate()->firstOrFail();
            $order=GoldTransaction::whereKey($id)->lockForUpdate()->firstOrFail();
            $suffix=$d['action']==='receipt'?'receipt':'phase';
            $key=isset($d['step_id']) ? "trading-v3:gold:{$id}:{$d['step_id']}:{$suffix}" : null;
            if($d['action']!=='claim' && $key && ($prior=InventoryMovement::where('idempotency_key',$key)->first())) {
                $this->assertOrderOwner($order,$bot,$d);
                abort_unless(hash_equals((string)($prior->meta['payload_hash']??''),$this->digest($d)),409,'Acknowledged body differs from original.');
                return $order;
            }
            $this->assertOwner($bot,$d['worker_id']);
            abort_unless((int)$bot->server_id===(int)$order->server_id,422,'Order and bot server_id differ.');
            if($d['action']==='claim') {
                if($order->processing_version===3 && $order->processing_owner===$d['worker_id'] && (int)$order->bot_id===$bot->id) return $order;
                $this->assertFinancialLease($bot,$d['worker_id']);
                abort_unless($order->status==='pending' && $order->processing_version===null,409,'Order is already claimed or terminal.');
                abort_if($order->bot_id && (int)$order->bot_id!==$bot->id,409,'Order is assigned to another bot.');
                $role=$order->type==='order'?'selling_main':'import_main';
                abort_unless(in_array($role,self::types($bot),true) && in_array($order->type,['order','import'],true),422,'Bot role does not match order direction.');
                abort_if(GoldTransaction::where('bot_id',$bot->id)->where('status','processing')->where('id','!=',$id)->exists()
                    || self::activeTransfers($bot->id)->isNotEmpty(),409,'Bot is busy with another gold operation.');
                $terms=$this->terms($order);
                abort_unless($terms['bars']>=0 && $terms['pure']>=0 && ($terms['bars']>0 || $terms['pure']>0)
                    && $terms['bars']<=intdiv(PHP_INT_MAX-$terms['pure'],InventoryMovementService::GOLD_PER_BAR)
                    && (int)$order->gold_qty===$terms['bars']*InventoryMovementService::GOLD_PER_BAR+$terms['pure'],422,'Order component quantities do not match converted gold.');
                abort_unless($terms['amount_vnd']>0 && $terms['price_at_transaction']>0,422,'Invalid frozen order amount or exchange rate.');
                $a=self::available($bot,$id);
                $waiting=$order->type==='order' ? ($a['bars']<$terms['bars'] || $a['pure']<$terms['pure']) : (self::receiveRoom($bot,$id)<$terms['bars']);
                $order->forceFill(['bot_id'=>$bot->id,'status'=>'processing','processing_version'=>3,'processing_owner'=>$d['worker_id'],
                    'processing_token'=>(string)Str::uuid(),'delivery_phase'=>$waiting?'waiting_stock':'waiting_customer',
                    'delivery_data'=>['claim'=>$terms,'progress'=>['bars'=>0,'pure'=>0]],'processing_started_at'=>now(),'updated_by'=>'app'])->save();
                return $order;
            }
            $this->assertOrderOwner($order,$bot,$d);
            abort_unless(($order->delivery_data['claim']??null)===$this->terms($order),409,'Order terms changed after claim.');
            abort_unless($order->status==='processing',409,'Order is terminal.');
            if(isset($d['character_id'])) {
                abort_if($order->character_id && (int)$order->character_id!==$d['character_id'],409,'Customer character ID changed.');
                $order->character_id=$d['character_id'];
            }
            if($d['action']==='phase') {
                abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'A reserved transfer must be settled first.');
                if(in_array($d['phase'],['trading','waiting_customer'],true)) $this->assertFinancialLease($bot,$d['worker_id']);
                $order->delivery_phase=$d['phase'];$order->save();
                $this->workflow($bot,$key,'phase',$id,'gold_transaction',['payload_hash'=>$this->digest($d)]);
                return $order;
            }
            abort_unless($order->character_id,422,'A verified customer character ID is required.');
            abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'Receipt cannot overlap an unsettled transfer.');
            $bars=(int)$d['bars_qty'];$pure=(int)$d['pure_gold_qty'];$r=self::remaining($order);
            abort_unless(($bars>0 || $pure>0) && $bars<=$r['bars'] && $pure<=$r['pure'],422,'Receipt exceeds remaining order quantities.');
            $kind=$order->type==='import'?'import':'sale';
            abort_unless($d['evidence']['kind']===$kind,422,'Receipt direction differs from order.');
            if($kind==='sale') {
                $a=self::available($bot,$id);abort_unless($a['bars']>=$bars && $a['pure']>=$pure,409,'Stock is reserved for another operation.');
            } else abort_unless(self::receiveRoom($bot,$id)>=$bars,409,'Incoming bars exceed account capacity or reservations.');
            $sign=$kind==='import'?1:-1;$snap=$d['bot_snapshot'];
            abort_unless((int)$snap['gold_bar_qty']===(int)$bot->gold_bar_qty+$sign*$bars
                && (int)$snap['gold_qty']===(int)$bot->gold_qty+$sign*$pure,409,'Stock snapshot does not match confirmed component receipt.');
            $fully=$bars===$r['bars'] && $pure===$r['pure'];
            abort_unless((bool)$d['completed']===$fully,422,'Completion flag does not match remaining order quantities.');
            $this->applySnapshot($bot,$snap,$kind,$order,$key,['payload_hash'=>$this->digest($d),'evidence'=>$d['evidence'],'bars_qty'=>$bars,'pure_gold_qty'=>$pure]);
            $data=$order->delivery_data;$data['progress']['bars']+=$bars;$data['progress']['pure']+=$pure;$data['last_step_id']=$d['step_id'];
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
    private function terms(GoldTransaction $o): array {
        return ['type'=>$o->type,'server_id'=>(int)$o->server_id,'character_name'=>$o->character_name,
            'bars'=>(int)$o->gold_bar_qty,'pure'=>(int)$o->pure_gold_qty,'gold_qty'=>(int)$o->gold_qty,
            'amount_vnd'=>(int)$o->amount_vnd,'price_at_transaction'=>(int)$o->price_at_transaction];
    }
    private function assertOrderOwner(GoldTransaction $o,Bot $b,array $d): void {
        abort_unless($o->processing_version===3 && $o->processing_owner===$d['worker_id'] && (int)$o->bot_id===$b->id
            && hash_equals((string)$o->processing_token,(string)$d['processing_token']),409,'Original order worker or token differs.');
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
    public function transferList(string $worker): array {
        return self::activeTransfers()->filter(fn($m)=>($m->meta['processing_owner']??null)===$worker)->map(fn($m)=>$m->meta)->values()->all();
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
            $terms=['id'=>$id,'processing_owner'=>$d['worker_id'],'from_bot_id'=>$from->id,'to_bot_id'=>$to->id,
                'server_id'=>(int)$from->server_id,'bars_qty'=>(int)$d['bars_qty'],'pure_gold_qty'=>(int)$d['pure_gold_qty']];
            if($row) {
                foreach($terms as $field=>$value) abort_unless(($row->meta[$field]??null)===$value,409,'Transfer UUID was already used for different terms.');
                if($d['action']==='claim') return $row->meta;
                abort_unless(hash_equals((string)$row->meta['processing_token'],(string)$d['processing_token']),409,'Transfer token differs.');
                if(($row->meta['status']??null)!=='claimed') {
                    abort_unless(($row->meta['last_step_id']??null)===$d['step_id'] && ($row->meta['last_action']??null)===$d['action']
                        && hash_equals((string)($row->meta['payload_hash']??''),$this->digest($d)),409,'Transfer is terminal or acknowledged body differs.');
                    return $row->meta;
                }
            } else abort_unless($d['action']==='claim',404,'Transfer reservation was not found.');
            if($d['action']==='claim') {
                $this->assertFinancialLease($from,$d['worker_id']);$this->assertFinancialLease($to,$d['worker_id']);
                abort_unless((int)$from->server_id===(int)$to->server_id,422,'Transfer business servers differ.');
                $storage=in_array('import_main',self::types($from),true) && self::auxiliary($to);
                $refill=self::auxiliary($from) && in_array('selling_main',self::types($to),true);
                abort_unless($storage || $refill,422,'Transfer direction is not importer-to-storage or storage-to-seller.');
                $this->assertTransferBoundary($from);$this->assertTransferBoundary($to);
                $a=self::available($from,null,$id);
                abort_unless(($terms['bars_qty']>0 || $terms['pure_gold_qty']>0) && $a['bars']>=$terms['bars_qty'] && $a['pure']>=$terms['pure_gold_qty'],409,'Transfer source has insufficient unreserved stock.');
                abort_unless(self::receiveRoom($to,null,$id)>=$terms['bars_qty'],409,'Transfer receiver lacks reserved account room.');
                $meta=[...$terms,'processing_token'=>(string)Str::uuid(),'status'=>'claimed','direction'=>$storage?'storage':'refill',
                    'from_before'=>['gold_qty'=>(int)$from->gold_qty,'gold_bar_qty'=>(int)$from->gold_bar_qty,'stock_version'=>(int)$from->stock_version],
                    'to_before'=>['gold_qty'=>(int)$to->gold_qty,'gold_bar_qty'=>(int)$to->gold_bar_qty,'stock_version'=>(int)$to->stock_version],
                    'created_at'=>now()->toIso8601String()];
                $this->workflow($from,$key,'reservation',null,'gold_transfer',$meta);return $meta;
            }
            $this->assertOwner($from,$d['worker_id']);$this->assertOwner($to,$d['worker_id']);
            $meta=$row->meta;
            if($d['action']==='cancel') {
                abort_unless($d['evidence']['unchanged']??false,422,'Cancellation requires a confirmed unchanged game state.');
                foreach(['from_before'=>$from,'to_before'=>$to] as $prefix=>$b) abort_unless((int)$b->gold_bar_qty===$meta[$prefix]['gold_bar_qty']
                    && (int)$b->gold_qty===$meta[$prefix]['gold_qty'] && (int)$b->stock_version===$meta[$prefix]['stock_version'],409,'Reserved stock changed; reconcile transfer receipt.');
                $meta['status']='cancelled';
            } else {
                $bars=$meta['bars_qty'];$pure=$meta['pure_gold_qty'];
                foreach([[$from,$d['from_snapshot'],-1,'from_before'],[$to,$d['to_snapshot'],1,'to_before']] as [$b,$snap,$sign,$prefix]) {
                    abort_unless((int)$b->gold_bar_qty===$meta[$prefix]['gold_bar_qty'] && (int)$b->gold_qty===$meta[$prefix]['gold_qty']
                        && (int)$b->stock_version===$meta[$prefix]['stock_version'],409,'Reserved transfer stock changed before settlement.');
                    abort_unless((int)$snap['gold_bar_qty']===(int)$b->gold_bar_qty+$sign*$bars
                        && (int)$snap['gold_qty']===(int)$b->gold_qty+$sign*$pure,409,'Transfer component snapshots do not balance.');
                    $this->applySnapshot($b,$snap,$sign<0?'transfer_out':'transfer_in',null,$key.($sign<0?':out':':in'),
                        ['transfer_id'=>$id,'payload_hash'=>$this->digest($d),'evidence'=>$d['evidence']]);
                }
                $meta['status']='completed';
            }
            $meta['last_step_id']=$d['step_id'];$meta['last_action']=$d['action'];$meta['payload_hash']=$this->digest($d);$meta['completed_at']=now()->toIso8601String();
            $row->update(['meta'=>$meta]);return $meta;
        },3);
        ApiCache::clearGroups(['public:bots']);return $result;
    }
    private function assertTransferBoundary(Bot $bot): void {
        abort_if(self::activeTransfers($bot->id)->isNotEmpty(),409,'Bot already has a reserved transfer.');
        foreach(GoldTransaction::where('bot_id',$bot->id)->where('status','processing')->get() as $order)
            abort_unless($order->processing_version===3 && $order->delivery_phase==='waiting_stock',409,'Order must wait for stock before an internal transfer.');
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
    private function digest(array $d): string {
        $sort=function(array $a) use (&$sort): array {ksort($a);foreach($a as $k=>$v) if(is_array($v)) $a[$k]=$sort($v);return $a;};
        return hash('sha256',json_encode($sort($d),JSON_THROW_ON_ERROR));
    }
}
