<?php
namespace App\Console\Commands;
use App\Events\NroShopUpdated;
use App\Services\NroDeliveryLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class NroRecoverReceipts extends Command {
    protected $signature='nro:recover-receipts';
    protected $description='Recover expired NRO execution attempts without replaying uncertain trades';
    public function handle(NroDeliveryLifecycle $flow): int {
        $accounts=$flow->expire();
        if($accounts) {
            \App\Support\ApiCache::clearGroup('public:nro-shop:listings');
            broadcast(new NroShopUpdated((string)Str::uuid(),true,DB::table('item_orders')->whereIn('account_id',$accounts)->distinct()->pluck('buyer_id')->map(fn($id)=>(int)$id)->all()));
        }
        return self::SUCCESS;
    }
}
