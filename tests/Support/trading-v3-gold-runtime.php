<?php
try {
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=(string)config('database.connections.sqlite.database');
if(!$app->environment('testing')||config('database.default')!=='sqlite'||!str_contains($db,'trading-v3-gold-db-'))throw new RuntimeException('Isolated gold SQLite required.');
if(($argv[1]??'')==='sanitize'){Illuminate\Support\Facades\DB::statement('PRAGMA secure_delete=ON');Illuminate\Support\Facades\DB::table('bots')->update(['account_name'=>'gold-test-redacted','account_password'=>'']);Illuminate\Support\Facades\DB::statement('VACUUM');echo "Sanitized gold test credentials.\n";exit;}
if(($argv[1]??'')==='restore-refill'){
    if(getenv('NRO_GOLD_TEST_REFILL_CUSTOMER')!=='true'||getenv('NRO_GOLD_TEST_REFILL_RESUME')!=='true')throw new RuntimeException('Explicit original refill resume required.');
    Illuminate\Support\Facades\DB::transaction(function(){
        $orders=App\Models\GoldTransaction::query()->lockForUpdate()->orderBy('id')->get();
        $sales=$orders->take(2);
        $return=$orders->count()===3?$orders->last():null;
        $bots=App\Models\Bot::query()->lockForUpdate()->orderBy('id')->get();
        $moves=App\Models\InventoryMovement::where('movement_type','reservation')->where('transaction_type','gold_transfer')->get();
        $safeReturn=$return===null||((int)$return->id===3&&$return->type==='import'&&$return->status==='pending'&&(int)$return->bot_id===2
            &&(int)$return->gold_bar_qty===3&&(int)$return->pure_gold_qty===0&&(int)$return->gold_qty===111000000&&(int)$return->amount_vnd===30000
            &&(int)$return->user_id===(int)$sales->first()?->user_id&&(int)$return->server_id===(int)$sales->first()?->server_id
            &&$return->character_name===$sales->first()?->character_name&&$return->delivery_phase===null&&empty($return->delivery_data)
            &&$return->processing_started_at===null&&$return->completed_at===null
            &&!App\Models\InventoryMovement::where('transaction_type','gold_transaction')->where('transaction_id',$return->id)->exists());
        if(!in_array($orders->count(),[2,3],true)||!$safeReturn||$sales->pluck('id')->all()!==[1,2]
            ||$sales->contains(fn($order)=>$order->type!=='order'||$order->status!=='completed'||(int)$order->bot_id!==1||(int)$order->pure_gold_qty!==0)
            ||$sales->pluck('gold_bar_qty')->map(fn($qty)=>(int)$qty)->all()!==[2,1]
            ||(int)App\Models\User::where('email','gold-v3-live@example.invalid')->value('balance')!==100000
            ||$bots->count()!==4||$bots->pluck('id')->all()!==[1,2,3,4]
            ||$bots->pluck('gold_bar_qty')->map(fn($qty)=>(int)$qty)->all()!==[0,0,996,999]
            ||$bots->pluck('status')->map(fn($status)=>(bool)$status)->all()!==[true,true,true,false]
            ||$bots->contains(fn($bot)=>(int)$bot->server_game_id!==11)
            ||(int)$bots[2]->map_id!==-1||(int)$bots[2]->area_number!==-1||$bots[2]->coordinates!==''
            ||$moves->count()!==2||$moves->contains(fn($move)=>($move->meta['status']??'')!=='completed'||(int)($move->meta['from_bot_id']??0)!==3||(int)($move->meta['to_bot_id']??0)!==1||(int)($move->meta['pure_gold_qty']??0)!==0)
            ||(int)$moves->sum(fn($move)=>(int)($move->meta['bars_qty']??0))!==3)throw new RuntimeException('Resume requires the original two settled sales, settled refill and three bars held by the customer.');
        foreach([1=>'SELL',2=>'IMPORT',3=>'AUX',4=>'OFF'] as $id=>$prefix){$user=getenv('NRO_GOLD_TEST_'.$prefix.'_USER');$password=getenv('NRO_GOLD_TEST_'.$prefix.'_PASSWORD');if(!$user||!$password)throw new RuntimeException('Ephemeral test account required.');
            // Credentials only: preserve stock versions, finished orders, original bot roles and auxiliary's invalid private point.
            Illuminate\Support\Facades\DB::table('bots')->where('id',$id)->update(['account_name'=>$user,'account_password'=>$password]);
        }
    });
    echo "Original refill fixture restored; only return the same three bars via import and storage.\n";exit;
}
if(($argv[1]??'')==='restore'){
    if(App\Models\GoldTransaction::count()!==2||App\Models\GoldTransaction::where('status','completed')->count()!==2
        ||(int)App\Models\User::where('email','gold-v3-live@example.invalid')->value('balance')!==110000)throw new RuntimeException('Resume only the two completed original orders.');
    foreach([1=>'SELL',2=>'IMPORT',3=>'AUX',4=>'OFF'] as $id=>$prefix){$user=getenv('NRO_GOLD_TEST_'.$prefix.'_USER');$password=getenv('NRO_GOLD_TEST_'.$prefix.'_PASSWORD');if(!$user||!$password)throw new RuntimeException('Ephemeral test account required.');
        Illuminate\Support\Facades\DB::table('bots')->where('id',$id)->update(['account_name'=>$user,'account_password'=>$password,'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'41','coordinates'=>'652-336']);
    }
    if(!App\Models\Bot::findOrFail(1)->status){echo "Original fixture restored; selling main remains OFF, only final collection is permitted.\n";}else throw new RuntimeException('Resume requires selling main OFF.');
    file_put_contents(dirname($db).'/gold-lost-ack','Restored marker: original controlled router committed DONE and lost first ACK.');exit;
}
$server=App\Models\Server::create(['name'=>'SV11 gold test','name_view'=>'SV11','status'=>true]);
Illuminate\Support\Facades\DB::table('server_game_login')->insert(['id'=>11,'name'=>'SV11','ip'=>'dragon11.teamobi.com','port'=>'14445']);
foreach([['SELL','selling_main',0,getenv('NRO_GOLD_TEST_IMPORT_CANCEL')!=='true'],['IMPORT','import_main',0,true],['AUX','selling_sub,import_sub',999,true],['OFF','selling_sub',999,false]] as [$prefix,$type,$bars,$enabled]){
    $user=getenv('NRO_GOLD_TEST_'.$prefix.'_USER');$password=getenv('NRO_GOLD_TEST_'.$prefix.'_PASSWORD');if(!$user||!$password)throw new RuntimeException('Ephemeral test account required.');
    App\Models\Bot::create(['name'=>'Gold test '.$prefix,'account_name'=>$user,'account_password'=>$password,'type'=>$type,
        'server_id'=>$server->id,'server_game_id'=>11,'gold_qty'=>0,'gold_bar_qty'=>$bars,'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'41','coordinates'=>'652-336','status'=>$enabled]);
}
App\Models\User::create(['email'=>'gold-v3-live@example.invalid','username'=>'goldv3tester','password'=>Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(32)),'balance'=>100000]);
if(getenv('NRO_GOLD_TEST_REFILL_CUSTOMER')==='true') {
    // The auxiliary has no usable private rendezvous; it must inherit the selected main's point.
    Illuminate\Support\Facades\DB::table('bots')->where('id',3)->update(['map_id'=>'-1','area_number'=>'-1','coordinates'=>'']);
}
echo "Created isolated SV11 gold fixture; no orders.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Gold fixture stopped: '.$error->getMessage().PHP_EOL);
    exit(1);
}
