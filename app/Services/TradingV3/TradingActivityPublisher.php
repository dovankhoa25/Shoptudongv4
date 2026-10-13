<?php

namespace App\Services\TradingV3;

use App\Jobs\PublishTradingBotActivities;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\{Cache, Log};
use Illuminate\Support\Str;

/** Transient activity only. Financial receipts and stock never enter this queue. */
class TradingActivityPublisher
{
    public static function labels(string $botType): array
    {
        $common = ['disabled'=>'Đã tắt', 'connecting'=>'Đang kết nối', 'reconnecting'=>'Đang kết nối lại',
            'reconciling'=>'Đang đối chiếu tồn và giao dịch', 'login_wait'=>'Đang chờ đăng nhập',
            'travelling'=>'Bot đang đến điểm đứng', 'ready'=>'Sẵn sàng', 'warmup'=>'Bot vừa vào game, đang chờ',
            'waiting_customer'=>'Đang chờ khách giao dịch', 'syncing'=>'Đang gửi kết quả lên web',
            'name_mismatch'=>'Tên đặt đơn chưa khớp ingame', 'customer_offline'=>'Đang chờ khách online',
            'trade_cancelled'=>'Giao dịch chưa hoàn tất', 'api_wait'=>'Đã xử lý trong game — đang chờ web xác nhận'];
        return $common + ($botType === 'gem_bot' ? [
            'paused'=>'Đã tạm dừng Ngọc tại QLTK', 'giving_item'=>'Đang giao đồ ký gửi',
            'waiting_consignment'=>'Đang chờ khách ký gửi', 'buying'=>'Đang mua đồ ký gửi', 'gifting'=>'Đang tặng ngọc',
            'review_required'=>'Đang kiểm tra kết quả giao dịch', 'error'=>'Đang tự thử lại',
            'out_of_items'=>'Bot hết đồ ký gửi — liên hệ CSKH', 'insufficient_gems'=>'Bot chưa đủ ngọc — liên hệ CSKH',
            'out_of_tickets'=>'Bot hết vé tặng ngọc',
        ] : [
            'paused'=>'Đã tạm dừng Vàng tại QLTK', 'waiting_stock'=>'Đang chờ bơm vàng hoặc giải phóng kho',
            'trading'=>'Đang giao dịch vàng', 'selling'=>'Đang giao vàng cho khách', 'importing'=>'Đang nhận vàng từ khách',
            'transferring'=>'Đang chuyển vàng giữa các bot', 'storage_idle'=>'Bot phụ đang nghỉ', 'source_empty'=>'Bot phụ hết nguồn vàng',
            'error'=>'Đang thử phục hồi', 'giving_bars'=>'Đang giao vàng cho khách', 'receiving_bars'=>'Đang nhận vàng từ khách',
            'storage'=>'Đang gom vàng vào bot phụ', 'refilling'=>'Đang bơm vàng cho bot bán', 'reserve_idle'=>'Bot phụ đang nghỉ',
            'capacity_full'=>'Bot đã đầy sức chứa vàng', 'out_of_bars'=>'Bot chưa đủ thỏi vàng',
        ]);
    }

    public static function cacheKey(string $type, int $id): string { return 'trading-v3:activity:'.$type.':'.$id; }

    public function publish(array $activities, array $notices = []): bool
    {
        if (!$activities && !$notices) return true;
        try {
            $connection = (string) config('trading_v3.activity_queue_connection', 'redis');
            if (!in_array(config('queue.connections.'.$connection.'.driver'), ['redis', 'database', 'beanstalkd', 'sqs'], true)) {
                throw new \RuntimeException('An asynchronous activity queue is required.');
            }
            $cache = Cache::store(config('trading_v3.activity_cache_store', 'redis'));
            $frames = [];
            foreach ($activities as $activity) {
                $token = (string) Str::uuid();
                $key = self::cacheKey($activity['bot_type'], (int) $activity['bot_id']);
                $expires = \Illuminate\Support\Carbon::parse($activity['expires_at']);
                $cache->put($key, $token, $expires);
                $frames[] = ['key' => $key, 'token' => $token, 'activity' => $activity];
            }
            $job = (new PublishTradingBotActivities($frames, $notices))
                ->onConnection($connection)->onQueue((string) config('trading_v3.activity_queue', 'trading-activity'));
            app(Dispatcher::class)->dispatch($job);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Trading activity could not be queued', ['count' => count($activities)]);
            return false;
        }
    }
}
