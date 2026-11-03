<?php

namespace App\Services\TradingV3;

use App\Models\InventoryMovement;

/** Financial request projection used only to compare a durable tool receipt with its web ACK. */
final class GoldTransferAcknowledgement
{
    public static function request(array $request, array $terms): array
    {
        $body = array_intersect_key($request, array_flip([
            'action', 'server_id', 'from_bot_id', 'to_bot_id', 'gold_bar_template_id',
            'bars_qty', 'pure_gold_qty', 'step_id', 'evidence', 'from_snapshot', 'to_snapshot',
        ]));
        $body['server_id'] = (int)$terms['server_id'];
        $body['gold_bar_template_id'] = (int)($terms['gold_bar_template_id'] ?? 457);
        $body['pure_gold_qty'] = (int)($body['pure_gold_qty'] ?? 0);
        return self::withoutCredentials($body);
    }

    public static function read(array $meta, string $key): ?array
    {
        if (!in_array($meta['status'] ?? null, ['completed', 'cancelled'], true)) return null;
        if (is_array($meta['acknowledged_request'] ?? null)) {
            return self::request($meta['acknowledged_request'], $meta);
        }
        // An old cancellation has no receipt ledger; never manufacture its missing evidence.
        if (($meta['status'] ?? null) !== 'completed' || ($meta['last_action'] ?? null) !== 'receipt'
            || empty($meta['last_step_id']) || empty($meta['payload_hash'])) return null;
        $rows = InventoryMovement::whereIn('idempotency_key', [$key.':out', $key.':in'])
            ->get()->keyBy('idempotency_key');
        $snapshots = []; $evidence = null;
        foreach (['out' => 'from', 'in' => 'to'] as $suffix => $side) {
            $row = $rows->get($key.':'.$suffix); $data = $row?->meta ?? [];
            if (!$row || (int)$row->bot_id !== (int)$meta[$side.'_bot_id']
                || (int)$row->server_id !== (int)$meta['server_id']
                || $row->transaction_type !== 'gold_transfer' || $row->movement_type !== 'transfer_'.$suffix
                || ($data['transfer_id'] ?? null) !== $meta['id']
                || !hash_equals((string)$meta['payload_hash'], (string)($data['payload_hash'] ?? ''))
                || (int)($data['gold_bar_template_id'] ?? 457) !== (int)($meta['gold_bar_template_id'] ?? 457)
                || !is_array($data['reported_snapshot'] ?? null) || !is_array($data['evidence'] ?? null)) return null;
            if ($evidence !== null && self::canonical($evidence) !== self::canonical($data['evidence'])) return null;
            $evidence = $data['evidence']; $snapshots[$side.'_snapshot'] = $data['reported_snapshot'];
        }
        return self::request([
            'action' => 'receipt', 'step_id' => $meta['last_step_id'],
            'from_bot_id' => $meta['from_bot_id'], 'to_bot_id' => $meta['to_bot_id'],
            'bars_qty' => $meta['bars_qty'], 'pure_gold_qty' => $meta['pure_gold_qty'] ?? 0,
            'evidence' => $evidence, ...$snapshots,
        ], $meta);
    }

    private static function withoutCredentials(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string)$key), ['worker_id', 'processing_token', 'account_name',
                'account_password', 'password', 'proxy', 'hmac_key', 'hmac_secret', 'authorization'], true)) {
                unset($value[$key]);
            } elseif (is_array($item)) $value[$key] = self::withoutCredentials($item);
        }
        return $value;
    }

    private static function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = self::canonical($item);
        return $value;
    }
}
