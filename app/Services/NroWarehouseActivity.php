<?php
namespace App\Services;

use App\Models\NroAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NroWarehouseActivity
{
    public const MESSAGES = [
        'home' => 'Bot đang về nhà lấy đồ',
        'trading' => 'Bot đang giao đồ cho khách; các khách khác tiếp tục chờ lượt',
        'dead' => 'Bot bị chết, đang hồi sinh và quay lại điểm nhận',
        'collecting' => 'Bot đang lấy đồ từ rương',
        'travelling' => 'Bot đang đến điểm giao',
        'ready' => 'Bot đã sẵn sàng nhận giao dịch',
    ];
    // Caller holds the account lock; inventory/trade changes use the same lock.
    public function update(NroAccount $account, string $instance, string $phase, ?array $position = null, ?int $jobId = null): void
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
            'position' => $phase==='ready' ? $position : ($old['position'] ?? null),
            'pauseStartedAt' => $pausedAt, 'jobId'=>$phase==='ready' ? null : $jobId, 'workerInstance' => $instance, 'updatedAt' => now()->toIso8601String()]]);
    }
    public static function releasePreparation(object $job): void {
        $account=NroAccount::whereKey($job->account_id)->lockForUpdate()->first();
        $activity=$account?->delivery_activity ?? [];
        if (!$account || empty($activity['pauseStartedAt'])) return;
        // The batch receipt owns this pause. Settling its first order must not erase
        // the rendezvous of the other customers still waiting at the same warehouse.
        if(($activity['phase'] ?? null)==='trading') return;
        $owner=$activity['jobId'] ?? null;
        if ($owner !== null && (int)$owner !== (int)$job->id) return;
        if ($owner === null && DB::table('nro_worker_jobs')->where('account_id',$job->account_id)
            ->where('id','!=',$job->id)->where('status','processing')->where('lease_until','>',now())->exists()) return;
        // Resume clocks without advertising a bot that has not returned to the rendezvous.
        app(self::class)->update($account,$activity['workerInstance'] ?? '', 'ready');
        $account->update(['delivery_activity'=>null]);
        $orders=DB::table('item_orders')->where('account_id',$account->id)->select('id');
        DB::table('nro_delivery_sessions')->whereIn('order_id',$orders)->whereIn('status',['ready','preparing','queued'])
            ->update(['position_json'=>null,'updated_at'=>now()]);
    }
    public static function publicPayload(NroAccount $account, $jobs): array
    {
        $activity = $account->delivery_activity ?? [];
        $phase = $activity['phase'] ?? null;
        $live = $jobs->contains(fn ($job) => $job->worker_instance === ($activity['workerInstance'] ?? null) && $job->lease_until && Carbon::parse($job->lease_until)->isFuture());
        return ['position'=>$live ? ($activity['position'] ?? null) : null, 'phase' => $live ? $phase : null, 'message' => $live ? ($activity['message'] ?? null) : null,
            'pauseStartedAt' => $live ? ($activity['pauseStartedAt'] ?? null) : null,
            'preparing' => $live && in_array($phase, ['home','collecting','travelling','dead'])];
    }
}
