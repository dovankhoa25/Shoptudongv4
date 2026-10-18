<?php
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';
$request=Illuminate\Http\Request::capture();$app->instance('request',$request);$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
$db=(string)config('database.connections.sqlite.database');
if(!$app->environment('testing')||config('database.default')!=='sqlite'||!str_contains($db,'trading-v3-gold-db-')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);return;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$body=json_decode(file_get_contents('php://input'),true)??[];
$importCancel=getenv('NRO_GOLD_TEST_IMPORT_CANCEL')==='true';
$refillCustomer=getenv('NRO_GOLD_TEST_REFILL_CUSTOMER')==='true';
if(str_starts_with($path,'/testing-gold/')){
    if(!hash_equals((string)getenv('NRO_TRADING_V3_HMAC_SECRET'),$_SERVER['HTTP_X_TEST_KEY']??'')){http_response_code(403);return;}
    header('Content-Type: application/json');
    if($refillCustomer&&$_SERVER['REQUEST_METHOD']==='POST'&&in_array($path,['/testing-gold/refill-orders','/testing-gold/refill-import'],true)) {
        $name=$body['name']??'';
        if(!is_string($name)||!preg_match('/^[a-zA-Z0-9_-]{3,30}$/D',$name)){http_response_code(422);echo json_encode(['success'=>false]);return;}
        $result=Illuminate\Support\Facades\DB::transaction(function()use($path,$name) {
            $existing=App\Models\GoldTransaction::query()->lockForUpdate()->get();
            $selling=$path==='/testing-gold/refill-orders';
            if($selling?$existing->isNotEmpty():($existing->count()!==2||$existing->contains(fn($o)=>$o->type!=='order'||$o->status!=='completed')||(int)$existing->sum('gold_bar_qty')!==3))return null;
            $bot=App\Models\Bot::findOrFail($selling?1:2);$user=App\Models\User::where('email','gold-v3-live@example.invalid')->sole();$made=[];
            foreach($selling?[2,1]:[3] as $bars)$made[]=App\Models\GoldTransaction::create(['type'=>$selling?'order':'import','user_id'=>$user->id,'server_id'=>$bot->server_id,'bot_id'=>$bot->id,'character_name'=>$name,
                'gold_qty'=>37000000*$bars,'gold_bar_qty'=>$bars,'pure_gold_qty'=>0,'amount_vnd'=>10000*$bars,'price_at_transaction'=>3700,'status'=>'pending','updated_by'=>'web']);
            return ['order_ids'=>array_map(fn($o)=>$o->id,$made)];
        });
        if($result===null){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Original bounded refill batch exists or is not fully settled; never replay.']);return;}
        echo json_encode(['success'=>true,'data'=>$result]);return;
    }
    if($path==='/testing-gold/import-cancel-orders'&&$_SERVER['REQUEST_METHOD']==='POST'&&$importCancel){
        $cancel=$body['cancel_name']??'';$next=$body['next_name']??'';
        if(!is_string($cancel)||!is_string($next)||!preg_match('/^[a-zA-Z0-9_-]{3,30}$/D',$cancel)||!preg_match('/^[a-zA-Z0-9_-]{3,30}$/D',$next)||strcasecmp($cancel,$next)===0){http_response_code(422);echo json_encode(['success'=>false]);return;}
        $orders=Illuminate\Support\Facades\DB::transaction(function()use($cancel,$next){
            if(App\Models\GoldTransaction::query()->lockForUpdate()->exists())return null;
            $bot=App\Models\Bot::findOrFail(2);$user=App\Models\User::where('email','gold-v3-live@example.invalid')->sole();
            if((int)$bot->server_game_id!==11||(int)$bot->map_id!==84||(int)$bot->area_number!==41||$bot->coordinates!=='652-336')throw new RuntimeException('Authorized SV11 point required.');
            $created=[];foreach([$cancel,$next] as $name)$created[]=App\Models\GoldTransaction::create(['type'=>'import','user_id'=>$user->id,'server_id'=>$bot->server_id,'bot_id'=>$bot->id,'character_name'=>$name,
                'gold_qty'=>37000000,'gold_bar_qty'=>1,'pure_gold_qty'=>0,'amount_vnd'=>10000,'price_at_transaction'=>3700,'status'=>'pending','updated_by'=>'web']);
            return $created;
        });
        if($orders===null){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Original import batch already exists; never replay.']);return;}
        echo json_encode(['success'=>true,'data'=>['cancel_order_id'=>$orders[0]->id,'next_order_id'=>$orders[1]->id]]);return;
    }
    if($path==='/testing-gold/order'&&$_SERVER['REQUEST_METHOD']==='POST'){
        if($importCancel||$refillCustomer){http_response_code(403);echo json_encode(['success'=>false,'message'=>'This scenario permits only its bounded original batch.']);return;}
        $type=$body['type']??'';$name=$body['name']??'';$botId=$type==='order'?1:2;
        if(!in_array($type,['order','import'],true)||!preg_match('/^[a-zA-Z0-9]{3,30}$/',$name)||App\Models\GoldTransaction::where('type',$type)->exists()){http_response_code(422);echo json_encode(['success'=>false,'message'=>'One bounded one-bar order per type only.']);return;}
        $bot=App\Models\Bot::findOrFail($botId);$user=App\Models\User::where('email','gold-v3-live@example.invalid')->sole();
        $order=App\Models\GoldTransaction::create(['type'=>$type,'user_id'=>$user->id,'server_id'=>$bot->server_id,'bot_id'=>$bot->id,'character_name'=>$name,
            'gold_qty'=>37000000,'gold_bar_qty'=>1,'pure_gold_qty'=>0,'amount_vnd'=>10000,'price_at_transaction'=>3700,'status'=>'pending','updated_by'=>'web']);
        echo json_encode(['success'=>true,'data'=>(new App\Http\Resources\AppAuto\VersionThree\GoldOrderResource($order))->resolve()]);return;
    }
    if($path==='/testing-gold/report'){
        echo json_encode(['success'=>true,'data'=>['bots'=>App\Models\Bot::orderBy('id')->get(['id','name','type','status','gold_qty','gold_bar_qty','stock_version','map_id','area_number','coordinates']),
            'orders'=>App\Models\GoldTransaction::orderBy('id')->get(['id','type','status','gold_bar_qty','pure_gold_qty','delivery_phase','delivery_data'])->makeVisible(['delivery_data']),
            'transfers'=>App\Models\InventoryMovement::where('movement_type','reservation')->where('transaction_type','gold_transfer')->get(['id','meta']),
            'movements'=>App\Models\InventoryMovement::where('bot_type','bot')->get(['bot_id','transaction_id','transaction_type','asset_type','movement_type','quantity_delta','idempotency_key']),
            'balance'=>(int)App\Models\User::where('email','gold-v3-live@example.invalid')->value('balance'),
            'lost_ack'=>file_exists(dirname($db).'/gold-lost-ack')]]);return;
    }
    http_response_code(404);return;
}
$response=$kernel->handle($request);$marker=dirname($db).'/gold-lost-ack';
if(!$importCancel&&!$refillCustomer&&$_SERVER['REQUEST_METHOD']==='PUT'&&preg_match('#^/app/v3/gold-transactions/\d+$#',$path)&&($body['action']??'')==='receipt'&&($body['completed']??false)===true&&$response->getStatusCode()===200&&!file_exists($marker)){
    file_put_contents($marker,'Committed gold DONE; first ACK deliberately lost.');http_response_code(503);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'Test DONE ACK lost after commit.']);
}else{$response->send();}$kernel->terminate($request,$response);
