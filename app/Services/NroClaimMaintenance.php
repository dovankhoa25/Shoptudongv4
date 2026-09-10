<?php
namespace App\Services;
use App\Models\NroAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NroClaimMaintenance
{
    public function run(bool $snapshots=true): array
    {
        if(!Cache::add('nro:maintenance:next',true,15)) return [];
        try {
            $result=Cache::lock('nro:maintenance:lock',30)->get(function () use($snapshots) {
                $changed=app(NroDeliveryLifecycle::class)->expire();
                DB::transaction(function () use($snapshots) {
            // Refresh unconfirmed stock only; a complete snapshot does not expire with age.
            if ($snapshots) {
                $stale = NroAccount::where('usage_type', 'warehouse')->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('publish_status')->orWhere('publish_status', '!=', 'login_blocked'))
                    ->whereNull('last_synced_at')->where('snapshot_failures', '<', 3)
                    ->whereNotIn('id', DB::table('nro_worker_jobs')->select('account_id')->whereIn('status', ['queued', 'processing', 'review']))
                    ->whereNotIn('id', DB::table('nro_worker_jobs')->select('account_id')->where('updated_at', '>', now()->subMinutes(2)))
                    ->orderBy('id')->limit(10)->get();
                foreach ($stale as $a) {
                    $current = NroAccount::whereKey($a->id)->lockForUpdate()->first();
                    if (!$current || $current->last_synced_at !== null || $current->status !== 'active' || $current->snapshot_failures >= 3) continue;
                    if (!DB::table('nro_worker_jobs')->where('account_id', $a->id)->whereIn('status', ['queued', 'processing', 'review'])->exists()
                        && !DB::table('nro_worker_jobs')->where('account_id', $a->id)->where('updated_at', '>', now()->subMinutes(2))->exists())
                        DB::table('nro_worker_jobs')->insert(['account_id' => $a->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
                },3);
                return $changed;
            });
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) { Cache::forget('nro:maintenance:next');throw $e; }
    }
}
