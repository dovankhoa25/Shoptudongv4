<?php
namespace App\Console\Commands;

use App\Models\NroAccountSnapshot;
use App\Services\NroSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class NroRefreshCardSummaries extends Command
{
    protected $signature = 'nro:refresh-card-summaries';
    protected $description = 'Add disciple and inventory previews to snapshot cards without logging into game accounts';

    public function handle(): int
    {
        $count = 0;
        NroAccountSnapshot::select(['id', 'data_json', 'summary_json'])->chunkById(100, function ($snapshots) use (&$count) {
            foreach ($snapshots as $snapshot) {
                $summary = $snapshot->summary_json ?? [];
                if (array_key_exists('disciple', $summary) && array_key_exists('itemPreviews', $summary)) continue;
                $disciple = $snapshot->data_json['disciple'] ?? null;
                $summary['disciple'] = is_array($disciple) ? Arr::only($disciple, ['exists', 'hasDetails', 'name', 'power']) : null;
                $summary['itemPreviews'] = NroSnapshotService::cardItemPreviews($snapshot->data_json ?? []);
                $snapshot->timestamps = false;
                $snapshot->update(['summary_json' => $summary]);
                $count++;
            }
        });
        $this->info("Updated {$count} snapshot card summaries.");
        return self::SUCCESS;
    }
}
