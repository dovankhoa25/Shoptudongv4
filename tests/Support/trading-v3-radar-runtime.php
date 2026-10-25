<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=(string)config('database.connections.sqlite.database');
if(!$app->environment('testing')||config('database.default')!=='sqlite'||!str_contains($db,'trading-v3-radar-db-'))throw new RuntimeException('Isolated radar database required.');
if(($argv[1]??'')==='sanitize') {
    Illuminate\Support\Facades\DB::statement('PRAGMA secure_delete=ON');
    Illuminate\Support\Facades\DB::table('bots')->update(['account_name'=>'radar-test-redacted','account_password'=>'']);
    Illuminate\Support\Facades\DB::statement('VACUUM');echo "Radar fixture sanitized.\n";exit;
}
if(($argv[1]??'')==='preflight') {
    App\Models\Bot::where('id',1)->update(['radar_qty'=>3,'bag_capacity'=>20,'bag_free_slots'=>10,'type'=>'selling_main,import_main']);
    echo "Disposable preflight inventory only; no game run.\n";exit;
}
if(($argv[1]??'')==='restore-refill') {
    $order=App\Models\GoldTransaction::sole();
    if($order->character_name!=='radarWarehouseProbe'||$order->status!=='pending'||!empty($order->delivery_data)
        ||App\Models\InventoryMovement::where('transaction_type','gold_transfer')->where('movement_type','reservation')->where('meta->status','completed')->count()!==1)
        throw new RuntimeException('Only the original warehouse refill may resume; no customer orders are recreated.');
    foreach([1=>'MAIN',2=>'AUX'] as $id=>$prefix){$bot=App\Models\Bot::findOrFail($id);
        if((int)$bot->server_game_id!==16||(int)$bot->radar_qty!==($id===1?0:3))throw new RuntimeException('Original warehouse inventory changed.');
        $bot->update(['account_name'=>getenv('NRO_RADAR_'.$prefix.'_USER'),'account_password'=>getenv('NRO_RADAR_'.$prefix.'_PASSWORD')]);}
    echo "Original isolated radar warehouse restored; no financial rows reset.\n";exit;
}
if(App\Models\Bot::exists())throw new RuntimeException('Fresh empty fixture required; never replay a financial run.');
$server=App\Models\Server::create(['name'=>'Super 2 radar isolated test','name_view'=>'supper 2','ip'=>'dragonsuper.teamobi.com','port'=>'17001','status'=>true]);
$server->forceFill(['gold_radar_enabled'=>true])->save();
Illuminate\Support\Facades\DB::table('server_game_login')->updateOrInsert(['id'=>16],['name'=>'supper 2','ip'=>'dragonsuper.teamobi.com','port'=>'17001']);
App\Models\GoldTradingPolicy::create(['server_id'=>$server->id,'asset_type'=>'radar','sell_enabled'=>true,'import_enabled'=>true,'import_stock_limit_bars'=>50]);
App\Models\GoldPrice::create(['server_id'=>$server->id,'price'=>3750,'import_price'=>3000,'status'=>true]);
foreach([['MAIN','selling_main',true],['AUX','selling_sub,import_sub',false]] as [$prefix,$type,$enabled]) {
    $user=getenv('NRO_RADAR_'.$prefix.'_USER');$password=getenv('NRO_RADAR_'.$prefix.'_PASSWORD');
    if(!$user||!$password)throw new RuntimeException('Ephemeral radar test credentials required.');
    App\Models\Bot::create(['name'=>'Radar test '.$prefix,'account_name'=>$user,'account_password'=>$password,'type'=>$type,'server_id'=>$server->id,'server_game_id'=>16,
        'gold_qty'=>0,'gold_bar_qty'=>0,'nrj_gold_bar_qty'=>0,'radar_qty'=>0,'bag_capacity'=>null,'bag_free_slots'=>null,
        'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'41','coordinates'=>'652-336','status'=>$enabled]);
}
App\Models\User::factory()->create(['email'=>'radar-test@example.invalid','username'=>'radarIsolatedTester','balance'=>100000]);
echo "Isolated Super 2 radar fixture created; no orders or shop purchases.\n";
