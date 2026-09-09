<?php
namespace App\Services;

use App\Models\NroAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NroWarehouseActivity
{
    public const MESSAGES = [
        'home' => 'Bot đang về nhà lấy đồ',
        'collecting' => 'Bot đang lấy đồ từ rương',
        'travelling' => 'Bot đang đến điểm giao',
        'ready' => 'Bot đã sẵn sàng nhận giao dịch',
    ];
    // Caller holds the account lock; inventory/trade changes use the same lock.
    public function update(NroAccount $account, string $instance, string $phase, ?array $position = null): void
    {
        $old = $account->delivery_activity ?? [];
        $pausedAt = $old['pauseStartedAt'] ?? null;
        if ($phase === 'ready') {
            if ($pausedAt) {
                foreach (DB::table('nro_delivery_sessions as s')->join('item_orders as o', 'o.id', '=', 's.order_id')
                    ->where('o.account_id', $account->id)->where('s.status', 'ready')->get(['s.id', 's.expires_at', 's.ready_at']) as $session) {
                    if (!$session->expires_at) continue;
                    $since = Carbon::parse($pausedAt)->max(Carbon::parse($session->ready_at ?? $pausedAt));
                    $seconds = max(0, $since->diffInSeconds(now(), false));
                    DB::table('nro_delivery_sessions')->where('id', $session->id)->update(['expires_at' => Carbon::parse($session->expires_at)->addSeconds($seconds)]);
                }
            }
            $pausedAt = null;
            if ($position) {
                foreach (DB::table('nro_delivery_sessions as s')->join('item_orders as o', 'o.id', '=', 's.order_id')
                    ->where('o.account_id', $account->id)->where('s.status', 'ready')->get(['s.id','s.position_json']) as $session) {
                    $data = array_merge(json_decode($session->position_json ?? '{}', true) ?: [], $position);
                    DB::table('nro_delivery_sessions')->where('id', $session->id)->update(['position_json' => json_encode($data)]);
                }
            }
        } else $pausedAt ??= now()->toIso8601String();
        $account->update(['delivery_activity' => ['phase' => $phase, 'message' => self::MESSAGES[$phase],
            'pauseStartedAt' => $pausedAt, 'workerInstance' => $instance, 'updatedAt' => now()->toIso8601String()]]);
    }
    public static function publicPayload(NroAccount $account, $jobs): array
    {
        $activity = $account->delivery_activity ?? [];
        $phase = $activity['phase'] ?? null;
        $live = $jobs->contains(fn ($job) => $job->worker_instance === ($activity['workerInstance'] ?? null) && $job->lease_until && Carbon::parse($job->lease_until)->isFuture());
        return ['phase' => $live ? $phase : null, 'message' => $live ? ($activity['message'] ?? null) : null,
            'pauseStartedAt' => $live ? ($activity['pauseStartedAt'] ?? null) : null,
            'preparing' => $live && in_array($phase, ['home','collecting','travelling'])];
    }
}
