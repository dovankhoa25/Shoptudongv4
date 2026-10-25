<?php
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';
$request=Illuminate\Http\Request::capture();$app->instance('request',$request);$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
$db=(string)config('database.connections.sqlite.database');
if(!$app->environment('testing')||config('database.default')!=='sqlite'||!str_contains($db,'trading-v3-radar-db-')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);return;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$body=json_decode(file_get_contents('php://input'),true)??[];
$marker=dirname($db).'/radar-lost-ack';
$warehouseOnly=getenv('NRO_RADAR_WAREHOUSE_ONLY')==='true';
if(str_starts_with($path,'/testing-radar/')) {
    if(!hash_equals((string)getenv('NRO_TRADING_V3_HMAC_SECRET'),$_SERVER['HTTP_X_TEST_KEY']??'')){http_response_code(403);return;}
    header('Content-Type: application/json');$user=App\Models\User::where('email','radar-test@example.invalid')->sole();
    if($path==='/testing-radar/order'&&$_SERVER['REQUEST_METHOD']==='POST') {
        $type=$body['type']??'';$name=$body['name']??'';
        if(!in_array($type,['order','import'],true)||!is_string($name)||!preg_match('/^[a-zA-Z0-9_-]{3,30}$/D',$name)
            ||App\Models\GoldTransaction::where('type',$type)->exists()){http_response_code(409);echo json_encode(['success'=>false,'message'=>'One original three-radar order per direction only.']);return;}
        Laravel\Passport\Passport::actingAs($user,['profile:read','profile:write'],'api');
        $data=['server_id'=>1,'character_name'=>$name,'gold_bar_template_id'=>281];
        $data=$type==='order'?[...$data,'money_amount'=>30000,'gold_type'=>'bar']:[...$data,'gold_bar_qty'=>3,'pure_gold_qty'=>0];
        $inner=Illuminate\Http\Request::create($type==='order'?'/api/orders':'/api/imports','POST',$data,[],[],['HTTP_ACCEPT'=>'application/json']);
        $response=$kernel->handle($inner);$response->send();$kernel->terminate($inner,$response);return;
    }
    if($path==='/testing-radar/phase'&&$_SERVER['REQUEST_METHOD']==='POST') {
        $main=App\Models\Bot::findOrFail(1);$aux=App\Models\Bot::findOrFail(2);$phase=$body['phase']??'';
        if($warehouseOnly&&$phase==='warehouse'&&!App\Models\GoldTransaction::exists()&&(int)$main->radar_qty===3&&(int)$aux->radar_qty===0&&!$aux->status) {
            $main->update(['type'=>'import_main']);$aux->update(['status'=>true]);echo json_encode(['success'=>true,'data'=>['phase'=>$phase]]);return;
        }
        if($phase==='import' && App\Models\GoldTransaction::count()===1 && App\Models\GoldTransaction::findOrFail(1)->status==='completed' && App\Services\TradingV3\GoldTradingService::activeTransfers()->isEmpty()) {
            $main->update(['type'=>'import_main']);echo json_encode(['success'=>true,'data'=>['phase'=>$phase]]);return;
        }
        $settled=($warehouseOnly||App\Models\GoldTransaction::whereIn('id',[1,2])->where('status','completed')->count()===2)
            &&App\Services\TradingV3\GoldTradingService::activeTransfers()->isEmpty();
        if(!$settled){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Original customer orders and transfers must settle first.']);return;}
        if($phase==='storage'&&(int)$main->radar_qty===3&&(int)$aux->radar_qty===0&&!App\Models\GoldTransaction::where('id','>',2)->exists()) {
            $main->update(['type'=>'import_main']);$aux->update(['status'=>true]);
        } elseif($phase==='refill'&&(int)$main->radar_qty===0&&(int)$aux->radar_qty===3&&!App\Models\GoldTransaction::where('id','>',2)->exists()) {
            $main->update(['type'=>'selling_main']);
            // Internal, uncharged demand only wakes refill. No third-party game trade is permitted.
            App\Models\GoldTransaction::create(['type'=>'order','user_id'=>$user->id,'server_id'=>1,'bot_id'=>1,'character_name'=>'radarWarehouseProbe',
                'gold_bar_template_id'=>281,'gold_bar_qty'=>3,'pure_gold_qty'=>0,'gold_qty'=>112500000,'amount_vnd'=>30000,'price_at_transaction'=>3750,'status'=>'pending']);
        } elseif($phase==='finish'&&(int)$main->radar_qty===3&&(int)$aux->radar_qty===0) {
            $demand=App\Models\GoldTransaction::where('character_name','radarWarehouseProbe')->sole();
            if($demand->status!=='pending'||!empty($demand->delivery_data)){http_response_code(409);return;}
            $demand->update(['status'=>'cancelled']);$aux->update(['status'=>false]);
        } else {http_response_code(409);echo json_encode(['success'=>false,'message'=>'Unexpected radar phase or stock; do not replay.']);return;}
        echo json_encode(['success'=>true,'data'=>['phase'=>$phase]]);return;
    }
    if($path==='/testing-radar/report') {
        echo json_encode(['success'=>true,'data'=>['bots'=>App\Models\Bot::orderBy('id')->get(['id','type','status','radar_qty','bag_capacity','bag_free_slots','gold_qty','stock_version']),
            'orders'=>App\Models\GoldTransaction::orderBy('id')->get(['id','type','status','gold_bar_template_id','gold_bar_qty','amount_vnd','delivery_data'])->makeVisible('delivery_data'),
            'transfers'=>App\Models\InventoryMovement::where('transaction_type','gold_transfer')->where('movement_type','reservation')->get(['meta']),
            'receipts'=>App\Models\InventoryMovement::whereIn('movement_type',['sale','import','transfer_out','transfer_in'])->get(['bot_id','movement_type','quantity_delta','idempotency_key']),
            'balance'=>(int)$user->balance,'lost_ack'=>file_exists($marker),
            'availability'=>app(App\Services\RadarTradingService::class)->summary(App\Models\Server::findOrFail(1))]]);return;
    }
    http_response_code(404);return;
}
$response=$kernel->handle($request);
if($_SERVER['REQUEST_METHOD']==='PUT'&&preg_match('#^/app/v3/gold-transactions/\d+$#',$path)&&($body['action']??'')==='receipt'
    &&($body['completed']??false)===true&&$response->getStatusCode()===200&&!file_exists($marker)) {
    file_put_contents($marker,'Committed radar terminal receipt; first HTTP ACK deliberately dropped.');http_response_code(503);echo json_encode(['success'=>false,'message'=>'Test ACK loss after commit.']);
} else {$response->send();}$kernel->terminate($request,$response);
