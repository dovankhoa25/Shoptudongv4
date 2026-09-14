<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** The customer contract. Job/session states are execution details, never order outcomes. */
class NroOrderFlow
{
    public const TERMINAL = ['completed', 'refunded'];
    public const ACTIVE_SESSIONS = ['queued', 'preparing', 'ready', 'trading', 'review'];
    public static function terminal(string $status): bool { return in_array($status, self::TERMINAL, true); }

    public static function terminalChanges(string $status, int $refund = 0): array
    {
        return ['failure_role'=>null,'failure_code'=>null, 'public_failure'=>null, 'login_retry_at'=>null,
            'cancel_requested'=>false, 'refund_requested'=>false,
            'delivery_message'=>$status === 'refunded' ? 'Đã hoàn '.number_format($refund,0,',','.').'đ. Đơn đã kết thúc.' : 'Đã nhận đủ vật phẩm. Đơn đã hoàn tất.'];
    }

    // Invoke only after settlement/refund is committed inside the same database transaction.
    public static function closeExecution(int $order, string $status): void
    {
        DB::table('nro_delivery_sessions')->where('order_id',$order)->whereIn('status',self::ACTIVE_SESSIONS)->update([
            'status'=>$status === 'completed' ? 'completed' : 'failed', 'trade_in_flight'=>false,
            'receiver_credentials'=>null,'receiver_lock'=>null,'position_json'=>null,
            'expires_at'=>null,'retry_at'=>null,'trade_phase'=>null,'phase_deadline'=>null,'updated_at'=>now()]);
        // Preserve previous failed/expired attempts and their timestamps for diagnosis.
        DB::table('nro_delivery_sessions')->where('order_id',$order)->update(['receiver_credentials'=>null,'receiver_lock'=>null]);
        DB::table('nro_worker_jobs')->where('order_id',$order)->update(['recovery_json'=>null]);
        DB::table('nro_worker_jobs')->where('order_id',$order)->whereIn('status',['queued','processing','review'])
            ->update(['status'=>$status === 'completed' ? 'completed' : 'failed','updated_at'=>now()]);
    }

    public static function present(array $data, object $order, ?object $account, bool $pendingRecovery, bool $activeJob, bool $accountAudit): array
    {
        $terminal=self::terminal($order->status);
        $business=$terminal ? $order->status : 'pending';
        $done=array_sum(array_column($data['items'],'delivered'));
        $total=array_sum(array_column($data['items'],'quantity'));
        $session=$data['session'] ?? null;
        $phase='not_requested'; $label='Chưa yêu cầu nhận';
        $message='Đồ được giữ cho đơn. Bấm nhận khi bạn sẵn sàng.';
        $configured=$account && $account->status==='active' && $account->server_id && $account->server_game_id;
        $canReceive=$configured && $order->status==='awaiting_receipt' && !$activeJob && !$pendingRecovery && !$accountAudit
            && !$order->cancel_requested && $order->failure_code!=='missing_items' && $account?->publish_status!=='login_blocked'
            && !in_array($session['status'] ?? '',self::ACTIVE_SESSIONS,true);
        $retryAt=$session['retryAt'] ?? null;
        $cooldown=$retryAt && \Carbon\Carbon::parse($retryAt)->isFuture();
        $canRequestRefund=!$terminal && !$done && !$order->cancel_requested && !$pendingRecovery
            && $order->status!=='review' && NroOrderRefund::eligible($order,$pendingRecovery);
        $canAdminRefund=!$terminal && !$done && !$pendingRecovery && !$activeJob && !$accountAudit
            && $order->status==='awaiting_receipt' && !in_array($session['status'] ?? '',self::ACTIVE_SESSIONS,true);

        if ($terminal) {
            $phase='closed'; $label='Đơn đã kết thúc';
            $message=self::terminalChanges($order->status,(int)$order->refund_amount)['delivery_message'];
            $data=array_replace($data,['session'=>null,'botOnline'=>false,'botLeaseUntil'=>null,'botActivity'=>null,
                'failureCode'=>null,'publicFailure'=>null,'loginRetryAt'=>null,'cancelRequested'=>false,'refundRequested'=>false]);
            $canReceive=$canRequestRefund=$canAdminRefund=false;
        } elseif ($order->cancel_requested) {
            $phase='stopping';$label='Đang dừng để hoàn tiền';
            $message='Đang dừng phiên và xác nhận kết quả lượt giao. Không cần gửi lại yêu cầu.';
        } elseif ($accountAudit) {
            $phase='checking_stock';$label='Đang kiểm tra kho';$message='Shop đang kiểm tra tồn kho của đơn. Phần chưa nhận vẫn được giữ.';
        } elseif ($order->failure_code==='missing_items') {
            $phase='needs_stock';$label='Cần bổ sung đồ';
            $message=$done ? 'Kho cần bổ sung phần còn lại để bạn nhận đủ. Đơn đã nhận một phần không thể hoàn tiền.' : 'Kho thiếu đúng vật phẩm. Bạn có thể chờ bổ sung hoặc yêu cầu hủy hoàn tiền.';
        } elseif ($order->failure_code==='login_failed' || $account?->publish_status==='login_blocked') {
            $phase='needs_input';$label='Cần sửa thông tin nhận hoặc acc kho';
            $sender=$account?->publish_status==='login_blocked' || ($order->failure_role ?? null)==='sender';
            $label=$sender ? 'Acc kho cần xử lý' : 'Kiểm tra acc nhận';
            $message=$sender ? 'Acc kho cần shop xử lý thông tin đăng nhập.' : 'Kiểm tra lại thông tin acc nhận trước khi nhận tiếp.';
            if($pendingRecovery) $message.=' Thông tin lượt giao trước vẫn được giữ để khôi phục.';
        } elseif (!$configured) {
            $phase='needs_input';$label='Kho cần được cấu hình';$message='Shop cần kiểm tra trạng thái và cấu hình server của acc kho. Phần chưa nhận vẫn được giữ.';
        } elseif (($pendingRecovery && (($session['status'] ?? '')!=='trading' || !$data['botOnline'])) || $order->status==='review') {
            $phase=$pendingRecovery ? 'recovering' : 'needs_attention';
            $label=$pendingRecovery ? 'Đang khôi phục lượt giao' : 'Cần kiểm tra lượt giao cũ';
            $message=$pendingRecovery ? 'Tool sẽ kiểm tra lượt giao đã lưu rồi tiếp tục phần còn lại. Không cần mua lại.' : 'Lượt giao cũ chưa có đủ dữ liệu để xác nhận. Shop cần kiểm tra nhật ký trước khi cho nhận tiếp hoặc hoàn tiền.';
        } elseif ($order->failure_code==='login_wait') {
            $phase='retrying';$label='Đang thử đăng nhập lại';$message='Bot đang thử kết nối lại theo thời gian chờ. Yêu cầu nhận vẫn được giữ.';
        } elseif ($cooldown) {
            $phase='cooldown';$label='Tạm nghỉ giao dịch';$message='Lượt trước bị hủy hoặc quá thời gian. Bạn có thể nhận lại khi hết thời gian nghỉ.';
        } elseif ($data['botActivity']['preparing'] ?? false) {
            $phase=$data['botActivity']['phase'] ?? 'preparing';$label='Bot đang chuẩn bị đồ';
            $message=($data['botActivity']['message'] ?? $label).'. Đồng hồ chờ khách đang tạm dừng.';
        } elseif (($session['status'] ?? '')==='trading') {
            $phase='trading';$label='Đang giao đồ';$message=($session['tradePhase'] ?? '')==='confirming' ? 'Đã khóa giao dịch, đang chờ hoàn tất.' : 'Đã mở giao dịch, hãy khóa giao dịch trong thời gian quy định.';
        } elseif ($activeJob) {
            if (($session['status'] ?? '')==='ready' && $data['botOnline']) {
                $phase=($data['botActivity']['servingOther'] ?? false) ? 'waiting_bot' : 'waiting_customer';
                $label=$phase==='waiting_bot' ? 'Bot đang giao đơn khác' : 'Sẵn sàng nhận đồ';
                $message=$phase==='waiting_bot' ? 'Bạn có thể đến điểm hẹn và chờ bot rảnh. Mỗi lượt giao đúng một đơn.' : 'Bot đã đến điểm hẹn. Khách tự nhận có thể mời giao dịch; nhận hộ sẽ được tool thực hiện.';
            } else {
                $phase=$order->status==='queued' ? 'queued' : ($data['botOnline'] ? 'logging_in' : 'reconnecting');
                $label=['queued'=>'Chờ tool','logging_in'=>'Đang đăng nhập / chuẩn bị','reconnecting'=>'Đang kết nối lại'][$phase];
                $message=$phase==='queued' ? 'Yêu cầu nhận đã được ghi nhận. Không cần bấm lại.' : 'Đang chuẩn bị phiên nhận. Điểm hẹn sẽ hiển thị khi được xác nhận.';
            }
        } elseif (($session['status'] ?? '')==='expired') {
            $label='Hết thời gian chờ';$message='Đồ chưa nhận vẫn được giữ. Bấm nhận lại khi sẵn sàng.';
        } elseif ($session) {
            $label='Có thể nhận tiếp';$message='Lượt nhận trước đã dừng. Phần chưa nhận vẫn được giữ cho đơn.';
        }
        if (in_array($phase,['retrying','reconnecting','recovering','needs_input','needs_attention'],true)) {
            if ($data['session'] ?? null) $data['session']['position']=null;
            if ($data['botActivity'] ?? null) $data['botActivity']['position']=null;
        }
        $data['message']=$message;
        $data['canCancel']=$canRequestRefund;
        $data['flow']=['state'=>$business,'label'=>['pending'=>'Chưa nhận đủ','completed'=>'Đã nhận đủ','refunded'=>'Đã hoàn tiền'][$business],
            'terminal'=>$terminal,'phase'=>$phase,'phaseLabel'=>$label,'message'=>$message,'delivered'=>$done,'total'=>$total,
            'remaining'=>max(0,$total-$done),'canReceive'=>$canReceive,'canRequestRefund'=>$canRequestRefund,
            'canAdminRefund'=>$canAdminRefund,'canCheckStock'=>!$terminal && !$accountAudit,
            'retryAt'=>$cooldown ? $retryAt : ($data['loginRetryAt'] ?? null), 'pendingRecovery'=>$pendingRecovery && !$terminal];
        return $data;
    }
}
