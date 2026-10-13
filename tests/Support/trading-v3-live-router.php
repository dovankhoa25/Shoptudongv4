<?php
// Local test router only. This is never registered in the application routes.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$request=Illuminate\Http\Request::capture();
$app->instance('request',$request);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$db=(string)config('database.connections.sqlite.database');
if(!$app->environment('testing') || config('database.default')!=='sqlite' || !str_contains($db,'trading-v3-runtime-db-') || ($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1') {
    http_response_code(403);echo 'Isolated test backend required.';return;
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$body=json_decode(file_get_contents('php://input'),true)??[];
if(str_starts_with($path,'/testing/')) {
    if(!hash_equals((string)getenv('NRO_TRADING_V3_HMAC_SECRET'),$_SERVER['HTTP_X_TEST_KEY']??'')){http_response_code(403);return;}
    header('Content-Type: application/json');
    if($path==='/testing/order' && $_SERVER['REQUEST_METHOD']==='POST') {
        $method=$body['method']??'';$name=$body['name']??'';
        if(!in_array($method,['gift_ticket','consignment'],true) || !preg_match('/^[a-zA-Z0-9]{3,30}$/',$name) || App\Models\GemTransaction::where('delivery_method',$method)->exists()) {
            http_response_code(422);echo json_encode(['success'=>false,'message'=>'Only one bounded 30-gem order per method.']);return;
        }
        $bot=App\Models\GemBot::sole();
        $user=App\Models\User::firstOrCreate(['email'=>'v3-live-test@example.invalid'],['username'=>'v3livetester','password'=>Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(32))]);
        $order=App\Models\GemTransaction::create(['user_id'=>$user->id,'bot_id'=>$bot->id,'server_id'=>$bot->server_id,'character_name'=>$name,'gem_qty'=>30,'amount_vnd'=>10000,'price_at_transaction'=>30,'delivery_method'=>$method,'status'=>'pending','updated_by'=>'web']);
        echo json_encode(['success'=>true,'data'=>(new App\Http\Resources\AppAuto\VersionThree\GemOrderResource($order))->resolve()]);return;
    }
    if($path==='/testing/report') {
        echo json_encode(['success'=>true,'data'=>['orders'=>App\Models\GemTransaction::orderBy('id')->get(['id','status','delivery_method','bot_gem_spent_qty','delivered_gem_qty','tickets_used_qty','delivery_data'])->makeVisible(['delivery_method','bot_gem_spent_qty','delivered_gem_qty','tickets_used_qty','delivery_data']),
            'movements'=>App\Models\InventoryMovement::whereNotNull('transaction_id')->get(['transaction_id','asset_type','quantity_delta','idempotency_key','meta']),
            'lost_ack_injected'=>file_exists(dirname($db).'/lost-ack-used')]]);return;
    }
    http_response_code(404);return;
}
$response=$kernel->handle($request);
$marker=dirname($db).'/lost-ack-used';
if($_SERVER['REQUEST_METHOD']==='PUT' && preg_match('#^/app/v3/gem-transactions/\d+$#',$path) && ($body['action']??'')==='receipt' && ($body['completed']??false)===true && $response->getStatusCode()===200 && !file_exists($marker)) {
    file_put_contents($marker,'DONE committed; first HTTP ACK deliberately lost.');
    http_response_code(503);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'Live test: DONE committed; ACK delivery lost.']);
} else {$response->send();}
$kernel->terminate($request,$response);
