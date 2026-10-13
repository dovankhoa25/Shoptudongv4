<?php
// Read-only final verification; never logs in or replays completed game actions.
$root=realpath($argv[1]??'');$base=realpath('D:/code/App dev/NRO_NATIVE_SLEEP_247/qltk/NroTradingV3/gold-live-results');
if(!$root||!$base||!str_starts_with(strtolower($root),strtolower($base.DIRECTORY_SEPARATOR)))throw new RuntimeException('Original gold report directory required.');
$reportPath=$root.'/gold-results.json';$report=json_decode(file_get_contents($reportPath),true,512,JSON_THROW_ON_ERROR);
$passes=array_filter($report['cases'],fn($c)=>($c['case']??'')==='resume_final_collection'&&($c['result']??'')==='PASS');
if(count($passes)!==1||!end($passes)['no_customer_order_replayed'])throw new RuntimeException('A real successful original collection must precede verification.');
$db=new PDO('sqlite:'.$root.'/test-database-no-credentials.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA query_only=ON');
$bots=$db->query('SELECT id,status,gold_bar_qty,gold_qty,stock_version,map_id,area_number,coordinates FROM bots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if(count($bots)!==4||array_map(fn($b)=>(int)$b['gold_bar_qty'],$bots)!==[0,0,999,999]||(int)$bots[0]['status']!==0||(int)$bots[3]['status']!==0)throw new RuntimeException('Final bag stocks or OFF states differ.');
foreach($bots as $bot)if((int)$bot['map_id']!==84||(int)$bot['area_number']!==41||$bot['coordinates']!=='652-336')throw new RuntimeException('Configured final point differs.');
$orders=$db->query('SELECT id,type,status,gold_bar_qty,pure_gold_qty,delivery_data FROM gold_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if(count($orders)!==2)throw new RuntimeException('Customer order was duplicated.');
foreach($orders as $order){$data=json_decode($order['delivery_data'],true,512,JSON_THROW_ON_ERROR);if($order['status']!=='completed'||(int)$order['gold_bar_qty']!==1||(int)$order['pure_gold_qty']!==0||($data['progress']['bars']??0)!==1||($data['progress']['pure']??0)!==0)throw new RuntimeException('Original customer order not settled exactly once.');}
$balance=(int)$db->query("SELECT balance FROM users WHERE email='gold-v3-live@example.invalid'")->fetchColumn();
if($balance!==110000||(int)$db->query("SELECT COUNT(*) FROM transactions WHERE idempotency_key='gold-import-credit:2'")->fetchColumn()!==1)throw new RuntimeException('Import wallet credited incorrectly.');
$steps=$db->query("SELECT transaction_id,quantity_delta FROM inventory_movements WHERE transaction_type='gold_transaction' AND asset_type='pure_gold' ORDER BY transaction_id")->fetchAll(PDO::FETCH_ASSOC);
if(count($steps)!==2||(int)$steps[0]['quantity_delta']!==-37000000||(int)$steps[1]['quantity_delta']!==37000000)throw new RuntimeException('Customer inventory was applied more than once.');
$transfers=$db->query("SELECT meta FROM inventory_movements WHERE transaction_type='gold_transfer' AND movement_type='reservation'")->fetchAll(PDO::FETCH_COLUMN);
foreach($transfers as $transfer){$meta=json_decode($transfer,true,512,JSON_THROW_ON_ERROR);if(!in_array($meta['status'],['completed','cancelled'],true))throw new RuntimeException('Unsettled internal reservation remains.');}
foreach(glob($root.'/state/gold/*.json') as $path){$state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);foreach(['intent','outbox','order','transfer','transfer_intent'] as $key)if(!empty($state[$key]))throw new RuntimeException('Financial journal still pending.');}
$now=gmdate('c');$report['cases'][]=['case'=>'read_only_final_verification','result'=>'PASS','at'=>$now,'orders_completed'=>2,'one_import_credit'=>true,'no_pending_journal'=>true,'active_pool_bars'=>999,'no_game_login_or_replay'=>true];
$report['backend']=['bots'=>$bots,'orders'=>$orders,'balance'=>$balance,'import_credit_count'=>1,'customer_inventory_steps'=>$steps,'internal_reservations'=>array_map(fn($m)=>json_decode($m,true,512,JSON_THROW_ON_ERROR),$transfers)];
$report['success']=true;$report['finished_at']=$now;$report['verification_note']='Real collector PASS was recorded before a test-report JSON parenting error; fixed serializer and verified the archived original ledger/journals read-only. No game operation repeated.';
file_put_contents($reportPath,json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "Gold final verification PASS: two orders, one wallet credit, active pool999, all journals settled; no login or replay.\n";
