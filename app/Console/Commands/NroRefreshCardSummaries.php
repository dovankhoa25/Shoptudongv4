<?php
namespace App\Console\Commands;

use App\Services\NroCardSummary;
use App\Services\NroSnapshotService;
use App\Support\ApiCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NroRefreshCardSummaries extends Command
{
    protected $signature = 'nro:refresh-card-summaries';
    protected $description = 'Refresh small listing previews from stored snapshots without changing inventory';
    public function handle(): int
    {
        $count = 0;
        DB::table('nro_account_snapshots')->select(['id', 'summary_json', 'data_json'])->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $data = json_decode($row->data_json, true);
                if (!is_array($data)) continue;
                $previous = json_decode($row->summary_json, true) ?: [];
                $summary = array_replace($previous, NroCardSummary::fromData($data), ['itemPreviews' => NroSnapshotService::cardItemPreviews($data)]);
                if ($summary === $previous) continue;
                DB::table('nro_account_snapshots')->where('id', $row->id)->update(['summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);
                $count++;
            }
        });
        ApiCache::clearGroups(['public:nick']);
        $this->info("Updated {$count} snapshot card summaries.");
        return self::SUCCESS;
    }
}
