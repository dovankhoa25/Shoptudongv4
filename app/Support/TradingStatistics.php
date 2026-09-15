<?php
namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class TradingStatistics
{
    /** Aggregate the already-filtered, still-authorized query in one SQL request. */
    public static function read(Builder $query, string $asset): array
    {
        if (!in_array($asset, ['gold', 'gem'], true)) throw new \InvalidArgumentException('Unknown asset');
        $base = (clone $query)->toBase();
        $grammar = $base->getGrammar();
        $column = fn ($name) => $grammar->wrap($query->getModel()->qualifyColumn($name));
        $status = $column('status');
        $created = $column('created_at');
        $amount = $column('amount_vnd');
        $quantity = $column($asset.'_qty');
        $today = today()->toDateTimeString();
        $tomorrow = today()->addDay()->toDateTimeString();
        $expressions = ['COUNT(*) AS total_orders'];
        foreach (['pending', 'processing', 'completed', 'cancelled', 'refunded'] as $value) {
            $expressions[] = "COALESCE(SUM(CASE WHEN {$status} = '{$value}' THEN 1 ELSE 0 END), 0) AS {$value}_orders";
        }
        $expressions[] = "COALESCE(SUM(CASE WHEN {$status} = 'completed' THEN {$amount} ELSE 0 END), 0) AS total_revenue";
        $expressions[] = "COALESCE(SUM(CASE WHEN {$status} = 'completed' THEN {$quantity} ELSE 0 END), 0) AS total_quantity";
        $expressions[] = "COALESCE(SUM(CASE WHEN {$created} >= ? AND {$created} < ? THEN 1 ELSE 0 END), 0) AS today_orders";
        $expressions[] = "COALESCE(SUM(CASE WHEN {$status} = 'completed' AND {$created} >= ? AND {$created} < ? THEN {$amount} ELSE 0 END), 0) AS today_revenue";
        $data = (array) $base->reorder()->selectRaw(implode(', ', $expressions), [$today, $tomorrow, $today, $tomorrow])->first();
        foreach (['total', 'pending', 'processing', 'completed', 'cancelled', 'refunded', 'today'] as $key) $data[$key.'_orders'] = (int) $data[$key.'_orders'];
        $data[$asset === 'gold' ? 'total_gold_sold' : 'total_gems_sold'] = $data['total_quantity'];
        unset($data['total_quantity']);
        if ($asset === 'gold') {
            unset($data['refunded_orders']);
            $data['failed_orders'] = 0;
        }
        return $data;
    }
}
