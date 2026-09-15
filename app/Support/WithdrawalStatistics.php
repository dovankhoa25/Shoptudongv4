<?php
namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class WithdrawalStatistics
{
    public static function read(Builder $query): array
    {
        $base = (clone $query)->toBase();
        $grammar = $base->getGrammar();
        $column = fn ($name) => $grammar->wrap($query->getModel()->qualifyColumn($name));
        $status = $column('status');
        $created = $column('created_at');
        $amount = $column('amount');
        $net = $column('net_amount');
        $expressions = ['COUNT(*) AS total_requests'];
        foreach (['pending', 'approved', 'rejected', 'paid'] as $value) {
            $expressions[] = "COALESCE(SUM(CASE WHEN {$status} = '{$value}' THEN 1 ELSE 0 END), 0) AS {$value}_requests";
        }
        foreach (['amount', 'fee', 'net_amount'] as $name) $expressions[] = 'COALESCE(SUM('.$column($name)."), 0) AS total_{$name}";
        $expressions[] = "COALESCE(SUM(CASE WHEN {$status} = 'paid' THEN {$net} ELSE 0 END), 0) AS paid_amount";
        $expressions[] = "COALESCE(SUM(CASE WHEN {$status} = 'pending' THEN {$amount} ELSE 0 END), 0) AS pending_amount";
        $expressions[] = "COALESCE(SUM(CASE WHEN {$created} >= ? AND {$created} < ? THEN 1 ELSE 0 END), 0) AS today_requests";
        $expressions[] = "COALESCE(SUM(CASE WHEN {$created} >= ? AND {$created} < ? THEN {$amount} ELSE 0 END), 0) AS today_amount";
        $today = today()->toDateTimeString();
        $tomorrow = today()->addDay()->toDateTimeString();
        $data = (array) $base->reorder()->selectRaw(implode(', ', $expressions), [$today, $tomorrow, $today, $tomorrow])->first();
        foreach (['total', 'pending', 'approved', 'rejected', 'paid', 'today'] as $name) $data[$name.'_requests'] = (int) $data[$name.'_requests'];
        return $data;
    }
}
