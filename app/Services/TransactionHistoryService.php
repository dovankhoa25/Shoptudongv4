<?php

namespace App\Services;

use App\Models\TransactionHistory;
use Illuminate\Database\Eloquent\Model;

class TransactionHistoryService
{
    public static function logUpdate(
        string $transactionType,
        Model $transaction,
        array $oldData,
        array $newData,
        string $source,
        string $action = 'updated',
        ?int $adminUserId = null,
        ?int $botId = null,
        ?string $botType = null,
        ?array $meta = null,
        ?string $note = null
    ): ?TransactionHistory {
        $changedFields = self::buildChangedFields($oldData, $newData);

        if (empty($changedFields) && empty($meta) && empty($note)) {
            return null;
        }

        return TransactionHistory::create([
            'transaction_type' => $transactionType,
            'transaction_id'   => $transaction->id,
            'action'           => $action,
            'source'           => $source,
            'admin_user_id'    => $adminUserId,
            'bot_id'           => $botId,
            'bot_type'         => $botType,
            'old_data'         => $oldData,
            'new_data'         => $newData,
            'changed_fields'   => $changedFields,
            'meta'             => $meta,
            'note'             => $note,
            'ip_address'       => request()?->ip(),
            'user_agent'       => request()?->userAgent(),
        ]);
    }

    protected static function buildChangedFields(array $oldData, array $newData): array
    {
        $changed = [];

        $allKeys = array_unique(array_merge(array_keys($oldData), array_keys($newData)));

        foreach ($allKeys as $key) {
            $oldValue = $oldData[$key] ?? null;
            $newValue = $newData[$key] ?? null;

            if ($oldValue != $newValue) {
                $changed[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changed;
    }
}
