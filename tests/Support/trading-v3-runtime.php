<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=(string)config('database.connections.sqlite.database');
if(!$app->environment('testing') || config('database.default')!=='sqlite' || !str_contains($db,'trading-v3-runtime-db-')) throw new RuntimeException('Refusing non-isolated database.');
if(($argv[1]??'')==='sanitize') {
    Illuminate\Support\Facades\DB::table('gem_bots')->update(['account_name'=>'test-bot-redacted','account_password'=>'']);
    echo "Sanitized isolated test DB for failure evidence.\n";exit;
}
$user=getenv('TRADING_TEST_BOT_USERNAME');$password=getenv('TRADING_TEST_BOT_PASSWORD');
if(!$user||!$password)throw new RuntimeException('Missing ephemeral test credentials.');
if(($argv[1]??'')==='restore') {
    $bot=App\Models\GemBot::sole();$order=App\Models\GemTransaction::where('status','processing')->sole();
    if((int)$bot->server_game_id!==6 || $order->delivery_method!=='consignment' || $order->gem_qty!==30 || $order->bot_gem_spent_qty!==0 || !($order->delivery_data['delivery_item']['verified']??false))throw new RuntimeException('Only an already-delivered, unpurchased 30-gem test order can resume.');
    $bot->update(['account_name'=>$user,'account_password'=>$password]);
    file_put_contents(dirname($db).'/lost-ack-used','Preserved the earlier committed DONE ACK fault.');
    echo "Restored the existing isolated test order; no new orders.\n";exit;
}
$server=App\Models\Server::create(['name'=>'SV6 runtime smoke','name_view'=>'Server 6','status'=>true]);
Illuminate\Support\Facades\DB::table('server_game_login')->insert(['id'=>6,'name'=>'SV6 test','ip'=>'dragon6.teamobi.com','port'=>'14445']);
App\Models\GemBot::create(['name'=>'SV6 test bot','account_name'=>$user,'account_password'=>$password,'server_id'=>$server->id,'server_game_id'=>6,
    'gem_qty'=>0,'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'41','coordinates'=>'652-336','status'=>true]);
echo "Created isolated SV6 fixture. No orders.\n";
