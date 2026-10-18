<?php

namespace App\Services\TradingV3;

use App\Models\InventoryMovement;

final class ReceiptInventoryLedger
{
    /**
     * Allocate a confirmed operation separately from the inventory observation.
     * This is an accounting chain, not a claim about when unrelated changes occurred.
     */
    public static function record(array $attributes, int $before, int $after, int $operationDelta, bool $forceCorrection = false): void
    {
        $correction = $after - $before - $operationDelta;
        $correctFirst = $operationDelta < 0;
        $operationBefore = $correctFirst ? $after - $operationDelta : $before;
        $operationAfter = $operationBefore + $operationDelta;
        $adjustment = [
            ...$attributes,
            'movement_type' => 'sync_correction',
            'quantity_delta' => $correction,
            'balance_before' => $correctFirst ? $before : $operationAfter,
            'balance_after' => $correctFirst ? $operationBefore : $after,
            'transaction_id' => null,
            'transaction_type' => null,
            'idempotency_key' => $attributes['idempotency_key'].':sync',
            'source' => 'app',
            'meta' => [...$attributes['meta'], 'receipt_key' => $attributes['idempotency_key'],
                'classification' => 'unclassified_inventory'],
            'note' => 'Inventory reconciliation separate from confirmed operation; not attributed to a deposit',
        ];
        $needsCorrection = $correction !== 0 || $forceCorrection;
        if ($needsCorrection && $correctFirst) InventoryMovement::create($adjustment);
        InventoryMovement::create([
            ...$attributes,
            'quantity_delta' => $operationDelta,
            'balance_before' => $operationBefore,
            'balance_after' => $operationAfter,
        ]);
        if ($needsCorrection && !$correctFirst) InventoryMovement::create($adjustment);
    }
}
