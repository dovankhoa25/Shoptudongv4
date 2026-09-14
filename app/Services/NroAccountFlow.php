<?php
namespace App\Services;
class NroAccountFlow {
    public static function present(object $a, $jobs): array {
        $scan=$jobs->first(fn($j)=>$j->type==='snapshot');
        $data=$scan ? ($scan->status==='processing'?'scanning':'queued') : ($a->snapshot_failures>=3?'scan_stopped':($a->latest_snapshot_id?($a->last_synced_at?'ready':'needs_refresh'):'empty'));
        $login=$a->publish_status==='login_blocked'?'needs_fix':'available';
        $activity=NroWarehouseActivity::publicPayload($a,$jobs);
        $online=$jobs->contains(fn($j)=>$j->lease_until && $j->lease_until>now()->toDateTimeString());
        return ['data'=>$data,'dataLabel'=>['scanning'=>'Đang lấy dữ liệu','queued'=>'Chờ tool lấy dữ liệu','scan_stopped'=>'Dừng tự quét sau 3 lần lỗi','ready'=>'Đã có dữ liệu','needs_refresh'=>'Chờ cập nhật kho','empty'=>'Chưa có dữ liệu'][$data],
            'login'=>$login,'loginLabel'=>$login==='needs_fix'?'Cần sửa đăng nhập':'Thông tin đăng nhập chưa bị chặn',
            'visibility'=>$a->shop_hidden?'manual_hidden':($a->login_sale_blocked?'login_hidden':'visible'),
            'activityLabel'=>$activity['message'] ?? ($online?'Bot đang xử lý yêu cầu':'Bot offline'), 'online'=>$online];
    }
}
