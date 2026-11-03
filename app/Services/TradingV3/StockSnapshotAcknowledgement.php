<?php
namespace App\Services\TradingV3;

use Illuminate\Database\Eloquent\Model;

/** Snapshot uploads report observations; they never grant permission to operate a bot. */
final class StockSnapshotAcknowledgement
{
    public static function inspect(Model $bot, array $snapshot, array $fields): array
    {
        $submitted = (int)$snapshot['stock_version']; $current = (int)$bot->stock_version;
        $same = true;
        foreach ($fields as $field) if (array_key_exists($field, $snapshot)) {
            $value = $snapshot[$field]; $observed = $bot->getAttribute($field);
            if ($value === null ? $observed !== null : ($field === 'name' ? (string)$value !== (string)$observed : (int)$value !== (int)$observed)) $same = false;
        }
        $result = $submitted > $current ? 'applied' : ($submitted < $current ? 'stale' : ($same ? 'already_current' : 'version_conflict'));
        return ['stock_snapshot_result' => $result, 'submitted_stock_version' => $submitted,
            'snapshot_applied' => $result === 'applied', 'snapshot_stale' => in_array($result, ['stale', 'version_conflict'], true)];
    }
}
