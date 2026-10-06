<?php
namespace App\Services;

class NroLoginMessage {
    public const WAIT_CODES=['login_wait','server_maintenance','server_unresponsive'];
    public static function maintenance(?string $kind): bool { return in_array($kind,['ServerMaintenance','ServerUnresponsive'],true); }
    public static function retryAt(string $value): \Carbon\Carbon {
        // Worker sends UTC; SQL datetime columns use the application's configured timezone.
        return \Carbon\Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
    public static function code(?string $kind): string { return match($kind) {
        'ServerMaintenance'=>'server_maintenance','ServerUnresponsive'=>'server_unresponsive',default=>'login_wait',
    }; }
    public static function pauseForMaintenance(object $job): void {
        // A new receipt clock starts only after the bot confirms its position again.
        // Preserve encrypted receiver credentials and any round currently in flight.
        \Illuminate\Support\Facades\DB::table('nro_delivery_sessions')->where('id',$job->delivery_session_id)
            ->where('trade_in_flight',false)
            ->where(fn($q)=>$q->whereNotNull('expires_at')->orWhereNotNull('ready_at')->orWhereNotNull('position_json'))
            ->update(['expires_at'=>null,'ready_at'=>null,'position_json'=>null,'updated_at'=>now()]);
    }
    public static function waiting(?string $role, ?string $kind=null): string {
        if ($kind==='ServerMaintenance') return 'Server game thông báo bảo trì. Tool tạm dừng giao dịch, tự đăng nhập lại theo lịch và thử lại mỗi 10 phút nếu chưa vào được. Đơn vẫn được giữ.';
        if ($kind==='ServerUnresponsive') return 'Server game không phản hồi khi đăng nhập, có thể đang bảo trì. Tool sẽ tự thử lại sau 10 phút; đơn vẫn được giữ.';
        return ($role==='receiver' ? 'Acc nhận' : 'Acc kho').' đang thử kết nối lại. Hệ thống tự tiếp tục khi hết thời gian chờ; đơn vẫn được giữ.';
    }
    public static function failure(?string $role, ?string $kind): string {
        if ($role==='receiver') return match($kind) {
            'BadCredentials'=>'Tài khoản hoặc mật khẩu acc nhận không đúng. Sửa thông tin nhận để tiếp tục; đơn vẫn được giữ.',
            'UnsafePassword'=>'Mật khẩu acc nhận bị game từ chối vì không an toàn. Đổi mật khẩu rồi cập nhật thông tin nhận.',
            'AccountLocked'=>'Acc nhận bị game khóa. Dùng acc nhận khác hoặc xử lý khóa rồi nhận tiếp.',
            'receiver_login_exhausted'=>'Acc nhận không đăng nhập được sau 3 lần thử. Kiểm tra đúng server, tài khoản và mật khẩu rồi nhận lại.',
            'receiver_power_low'=>'Nhân vật nhận chưa đủ 340.000 sức mạnh. Tăng sức mạnh hoặc đổi acc nhận rồi thử lại.',
            'receiver_character_missing'=>'Không tìm thấy nhân vật nhận trên server của đơn. Kiểm tra server và acc đã gửi.',
            'receiver_map_locked'=>'Nhân vật nhận chưa đủ điều kiện tới map giao đồ. Hoàn thành điều kiện trong game hoặc đổi acc nhận.',
            'receiver_navigation_failed'=>'Tool chưa đưa được acc nhận tới điểm giao. Kiểm tra vị trí nhân vật rồi bấm nhận lại.',
            'inventory_full'=>'Hành trang acc nhận không đủ ô trống. Dọn túi rồi bấm nhận tiếp.',
            'recipient_absent'=>'Acc nhận chưa tới được điểm giao. Kiểm tra nhân vật rồi nhận tiếp.',
            'recipient_busy'=>'Nhân vật đang nhận đơn khác. Hoàn tất lượt trước rồi nhận đơn này.',
            'same_character'=>'Acc nhận trùng nhân vật acc kho. Chọn acc nhận khác.',
            default=>'Acc nhận chưa sẵn sàng. Kiểm tra thông tin nhận rồi thử lại.',
        };
        return match($kind) {
            'AccountLocked'=>'Acc kho bị game khóa. Bạn có thể yêu cầu hủy nếu chưa nhận món nào.',
            'BadCredentials','UnsafePassword'=>'Acc kho cần shop cập nhật thông tin đăng nhập để tiếp tục giao đồ.',
            'warehouse_bag_full'=>'Hành trang acc kho đầy. Shop cần dọn chỗ để giao phần đồ còn lại.',
            'warehouse_prepare_failed'=>'Kho chưa chuyển được món cần giao vào hành trang. Shop cần kiểm tra kho; đơn vẫn được giữ.',
            default=>'Acc kho chưa sẵn sàng. Shop cần xử lý để tiếp tục giao đồ.',
        };
    }
}
