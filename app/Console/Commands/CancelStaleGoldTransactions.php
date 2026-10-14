<?php
namespace App\Console\Commands;
use App\Models\{GemTransaction,GoldTransaction};
use App\Services\TradingV3\OrderTimeoutService;
use Illuminate\Console\Command;
class CancelStaleGoldTransactions extends Command {
    protected $signature='gold:cancel-stale';
    protected $description='Đóng đơn vàng/ngọc pending quá hạn và hoàn tiền sau khoảng chờ an toàn';
    public function handle(OrderTimeoutService $timeouts): int {
        $gc=$timeouts->expirePending(GoldTransaction::class,max(1,(int)config('trading.gold_order_pending_timeout_minutes',21)),'gold');
        $gr=$timeouts->refundElapsed(GoldTransaction::class,max(0,(int)config('trading.gold_order_refund_grace_minutes',3)),'gold');
        $nc=$timeouts->expirePending(GemTransaction::class,max(1,(int)config('trading.gem_order_pending_timeout_minutes',21)),'gem');
        $nr=$timeouts->refundElapsed(GemTransaction::class,max(0,(int)config('trading.gem_order_refund_grace_minutes',3)),'gem');
        $this->info("Vàng: đóng {$gc}, hoàn {$gr}. Ngọc: đóng {$nc}, hoàn {$nr}.");
        return self::SUCCESS;
    }
}
