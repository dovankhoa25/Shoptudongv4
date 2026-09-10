<?php
namespace App\Services;
use App\Models\NroAccount;
class NroSnapshotRetries {
    // Caller holds the account lock and has finalized this job exactly once.
    public static function failed(int $id, string $message): void {
        $a=NroAccount::findOrFail($id);
        $count=3;
        $updates=['snapshot_failures'=>$count];
        if($a->publish_status!=='login_blocked') $updates += ['publish_status'=>'scan_failed', 'publish_error'=>($count>=3?'Đã dừng yêu cầu lấy dữ liệu (tối đa 3 lần đăng nhập tự động). Bấm Lấy dữ liệu để thử lại. ':'').$message];
        $a->update($updates);
    }
}
