<?php

namespace App\Services;

use App\Models\NroAccount;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class NroReceivingService
{
    public function start(User $user, int $id, array $v): int
    {
        return DB::transaction(function () use ($user, $id, $v) {
            // Lock the buyer first, matching purchase lock ordering.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $o = DB::table('item_orders')->where('id', $id)->where('buyer_id', $user->id)->first();
            abort_unless($o, 404);
            $a = NroAccount::whereKey($o->account_id)->lockForUpdate()->firstOrFail();
            $o = DB::table('item_orders')->where('id', $id)->lockForUpdate()->first();
            $old = DB::table('nro_delivery_sessions')->where(['order_id' => $id, 'request_key' => $v['requestKey']])->first();
            if ($old) return $old->id;
            NroShopService::require($o->status === 'awaiting_receipt', 'Đơn đang nhận đồ hoặc cần đối soát.');
            NroShopService::require($a->status === 'active' && $a->server_id && $a->server_game_id, 'Kho cần được cấu hình server hiển thị và server đăng nhập.');
            NroShopService::require($a->publish_status !== 'login_blocked', $a->publish_error ?: 'Acc kho đang bị chặn đăng nhập. Shop cần sửa thông tin acc trước khi giao tiếp.');
            NroShopService::require(!DB::table('nro_delivery_sessions')->where('order_id', $id)->whereIn('status', ['queued', 'preparing', 'ready', 'trading', 'review'])->exists(), 'Đơn đã có phiên nhận.');
            $credentials = null;
            $lock = null;
            if ($v['mode'] === 'auto') {
                $username = trim($v['username']);
                NroShopService::require(!NroAccount::withTrashed()->where('server_game_id', $a->server_game_id)->whereRaw('LOWER(account_name) = ?', [mb_strtolower($username)])->exists(), 'Acc nhận không được là acc do hệ thống quản lý.');
                $lock = hash('sha256', $a->server_game_id . ':' . mb_strtolower($username));
                NroShopService::require(!DB::table('nro_delivery_sessions')->where('receiver_lock', $lock)->exists(), 'Acc nhận đang được dùng trong phiên khác.');
                $credentials = Crypt::encryptString(json_encode(['username' => $username, 'password' => $v['password']]));
            }
            $session = DB::table('nro_delivery_sessions')->insertGetId([
                'order_id' => $id,
                'request_key' => $v['requestKey'],
                'mode' => $v['mode'],
                'status' => 'queued',
                'trade_in_flight' => false,
                'recipient_name' => $v['mode'] === 'manual' ? $v['recipientName'] : null,
                'receiver_credentials' => $credentials,
                'receiver_lock' => $lock,
                'wait_minutes' => $a->wait_minutes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('item_orders')->where('id', $id)->update(['status' => 'queued', 'delivery_message' => 'Đã yêu cầu nhận đồ; đang chờ tool.', 'updated_at' => now()]);
            DB::table('nro_worker_jobs')->insert(['account_id' => $a->id, 'order_id' => $id, 'delivery_session_id' => $session, 'type' => 'delivery', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            return $session;
        }, 3);
    }

    public function retryInterrupted(object $job, ?string $message = null): bool
    {
        if (!$job->order_id || !$job->delivery_session_id) return false;
        $session = DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->lockForUpdate()->first();
        if (!$session || $session->trade_in_flight === null || (bool) $session->trade_in_flight) return false;
        if (!DB::table('item_order_items')->where('order_id', $job->order_id)->whereColumn('delivered', '<', 'quantity')->exists()) return false;
        DB::table('item_orders')->where('id', $job->order_id)->update([
            'status' => 'awaiting_receipt',
            'delivery_message' => $message ?: 'Phiên nhận đã dừng trước khi giao dịch hoặc sau lượt đã xác nhận. Đồ còn lại vẫn được giữ; bấm Nhận đồ để tiếp tục, không thanh toán lại.',
            'updated_at' => now(),
        ]);
        $this->finish($job, 'failed');
        DB::table('nro_worker_jobs')->where('id', $job->id)->update([
            'status' => 'failed',
            'result_json' => json_encode(['retrySafe' => true, 'message' => $message ?: 'Dừng ngoài giao dịch chưa xác nhận.']),
            'updated_at' => now(),
        ]);
        return true;
    }

    public function finish(object $job, string $status): void
    {
        if (!$job->delivery_session_id) return;
        DB::table('nro_delivery_sessions')->where('id', $job->delivery_session_id)->update([
            'status' => $status,
            'receiver_credentials' => null,
            // Keep the login lock on uncertain results until reconciliation.
            'receiver_lock' => $status === 'review' ? DB::raw('receiver_lock') : null,
            'updated_at' => now(),
        ]);
    }
}
