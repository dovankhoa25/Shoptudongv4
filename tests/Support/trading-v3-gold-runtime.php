<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=(string)config('database.connections.sqlite.database');
if(!$app->environment('testing')||config('database.default')!=='sqlite'||!str_contains($db,'trading-v3-gold-db-'))throw new RuntimeException('Isolated gold SQLite required.');
if(($argv[1]??'')==='sanitize'){Illuminate\Support\Facades\DB::statement('PRAGMA secure_delete=ON');Illuminate\Support\Facades\DB::table('bots')->update(['account_name'=>'gold-test-redacted','account_password'=>'']);Illuminate\Support\Facades\DB::statement('VACUUM');echo "Sanitized gold test credentials.\n";exit;}
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
foreach([['SELL','selling_main',0,true],['IMPORT','import_main',0,true],['AUX','selling_sub,import_sub',999,true],['OFF','selling_sub',999,false]] as [$prefix,$type,$bars,$enabled]){
    $user=getenv('NRO_GOLD_TEST_'.$prefix.'_USER');$password=getenv('NRO_GOLD_TEST_'.$prefix.'_PASSWORD');if(!$user||!$password)throw new RuntimeException('Ephemeral test account required.');
    App\Models\Bot::create(['name'=>'Gold test '.$prefix,'account_name'=>$user,'account_password'=>$password,'type'=>$type,
        'server_id'=>$server->id,'server_game_id'=>11,'gold_qty'=>0,'gold_bar_qty'=>$bars,'map_name'=>'Siêu thị','map_id'=>'84','area_number'=>'41','coordinates'=>'652-336','status'=>$enabled]);
}
App\Models\User::create(['email'=>'gold-v3-live@example.invalid','username'=>'goldv3tester','password'=>Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(32)),'balance'=>100000]);
echo "Created isolated SV11 gold fixture; no orders.\n";
