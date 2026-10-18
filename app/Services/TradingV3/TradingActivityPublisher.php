<?php

namespace App\Services\TradingV3;

use Illuminate\Support\Facades\Log;

/** Transient activity only. Financial receipts and stock never enter this transport. */
class TradingActivityPublisher
{
    public static function labels(string $botType): array
    {
        $common = ['disabled'=>'Đã tắt', 'connecting'=>'Đang kết nối', 'reconnecting'=>'Đang kết nối lại',
            'reconciling'=>'Đang đối chiếu tồn và giao dịch', 'login_wait'=>'Đang chờ đăng nhập',
            'travelling'=>'Bot đang đến điểm đứng', 'ready'=>'Sẵn sàng', 'warmup'=>'Bot vừa vào game, đang chờ',
            'waiting_customer'=>'Đang chờ khách giao dịch', 'syncing'=>'Đang gửi kết quả lên web',
            'name_mismatch'=>'Tên đặt đơn chưa khớp ingame', 'customer_offline'=>'Đang chờ khách online',
            'trade_cancelled'=>'Giao dịch chưa hoàn tất', 'api_wait'=>'Đã xử lý trong game — đang chờ web xác nhận',
            'checking_presence'=>'Đang kiểm tra dữ liệu nhân vật hai chiều', 'checker_idle'=>'Bot kiểm tra đang chờ lượt',
            'presence_wait'=>'Đang chờ dữ liệu nhân vật từ game', 'map_recovery'=>'Đang ra map lân cận rồi quay lại điểm đứng',
            'presence_ready'=>'Đã xác nhận nhìn thấy nhân vật hai chiều', 'packet_stalled'=>'Game chưa trả dữ liệu cần thiết',
            'presence_unverified'=>'Chưa đủ dữ liệu xác định bot bị kẹt', 'checker_recovery'=>'Đang khôi phục dữ liệu bot kiểm tra'];
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

    public function publish(array $activities, array $notices = []): bool
    {
        if (!$activities && !$notices) return true;
        try {
            // Only this request's payload remains in RAM until its response has been sent.
            // No job, queue, cache token, lease or durable activity state is created.
            $delivered = false;
            app()->terminating(function () use ($activities, $notices, &$delivered): void {
                if ($delivered) return;
                $delivered = true;
                try { app(TradingActivityDelivery::class)->deliver($activities, $notices); }
                catch (\Throwable $e) { $this->warn('Trading activity delivery failed'); }
            });
            return true;
        } catch (\Throwable $e) {
            $this->warn('Trading activity could not be scheduled');
            return false;
        }
    }

    private function warn(string $message): void
    {
        try { Log::warning($message); } catch (\Throwable $ignored) { }
    }
}
