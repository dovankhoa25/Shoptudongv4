<?php

namespace App\Services;

use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Model;

class InventoryMovementService
{
    public const GOLD_PER_BAR = 37_000_000;

    public static function convertedGold(int $goldQty, int $goldBarQty, int $nrjGoldBarQty=0, int $radarQty=0): int
    {
        return $goldQty + ($goldBarQty * self::GOLD_PER_BAR) + ($nrjGoldBarQty * GoldBarAsset::value(GoldBarAsset::NRJ)) + $radarQty * GoldBarAsset::value(GoldBarAsset::RADAR);
    }

    public static function recordGemChange(
        Model $bot,
        int $before,
        int $after,
        string $movementType,
        string $source,
        ?int $transactionId = null,
        ?string $transactionType = null,
        ?string $idempotencyKey = null,
        ?int $adminUserId = null,
        ?string $note = null,
        ?int $serverId = null,
        array $meta = []
    ): ?InventoryMovement {
        return self::record(
            serverId: $serverId ?? (int) $bot->server_id,
            botId: (int) $bot->id,
            botType: 'gem_bot',
            assetType: 'gem',
            before: $before,
            after: $after,
            movementType: $movementType,
            source: $source,
            transactionId: $transactionId,
            transactionType: $transactionType,
            idempotencyKey: $idempotencyKey,
            adminUserId: $adminUserId,
            note: $note,
            meta: $meta
        );
    }

    public static function recordGoldChange(
        Model $bot,
        int $beforeGold,
        int $beforeBars,
        int $afterGold,
        int $afterBars,
        string $movementType,
        string $source,
        ?int $transactionId = null,
        ?string $transactionType = null,
        ?string $idempotencyKey = null,
        ?int $adminUserId = null,
        ?string $note = null,
        ?int $serverId = null,
        ?int $beforeNrjBars = null,
        ?int $afterNrjBars = null
    ): ?InventoryMovement {
        $beforeNrjBars ??= (int) $bot->nrj_gold_bar_qty;
        $afterNrjBars ??= (int) $bot->nrj_gold_bar_qty;
        return self::record(
            serverId: $serverId ?? (int) $bot->server_id,
            botId: (int) $bot->id,
            botType: 'bot',
            assetType: 'pure_gold',
            before: self::convertedGold($beforeGold, $beforeBars, $beforeNrjBars, (int)$bot->radar_qty),
            after: self::convertedGold($afterGold, $afterBars, $afterNrjBars, (int)$bot->radar_qty),
            movementType: $movementType,
            source: $source,
            transactionId: $transactionId,
            transactionType: $transactionType,
            idempotencyKey: $idempotencyKey,
            adminUserId: $adminUserId,
            note: $note,
            meta: [
                'gold_per_bar' => self::GOLD_PER_BAR,
                'before_gold_qty' => $beforeGold,
                'before_gold_bar_qty' => $beforeBars,
                'after_gold_qty' => $afterGold,
                'after_gold_bar_qty' => $afterBars,
                'before_nrj_gold_bar_qty' => $beforeNrjBars,
                'after_nrj_gold_bar_qty' => $afterNrjBars,
            ]
        );
    }

    protected static function record(
        int $serverId,
        int $botId,
        string $botType,
        string $assetType,
        int $before,
        int $after,
        string $movementType,
        string $source,
        ?int $transactionId,
        ?string $transactionType,
        ?string $idempotencyKey,
        ?int $adminUserId,
        ?string $note,
        array $meta
    ): ?InventoryMovement {
        if ($idempotencyKey && InventoryMovement::where('idempotency_key', $idempotencyKey)->exists()) {
            return null;
        }

        if ($before === $after && $movementType !== 'opening_balance') {
            return null;
        }

        return InventoryMovement::create([
            'server_id' => $serverId,
            'bot_id' => $botId,
            'bot_type' => $botType,
            'asset_type' => $assetType,
            'movement_type' => $movementType,
            'quantity_delta' => $after - $before,
            'balance_before' => $before,
            'balance_after' => $after,
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
            'idempotency_key' => $idempotencyKey,
            'source' => $source,
            'admin_user_id' => $adminUserId,
            'meta' => $meta ?: null,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }
}
