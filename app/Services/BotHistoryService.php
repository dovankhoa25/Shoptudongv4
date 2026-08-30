<?php

// namespace App\Services;

// use App\Models\BotHistory;
// use Illuminate\Database\Eloquent\Model;

// class BotHistoryService
// {
//     /**
//      * Các field nên che khi log.
//      */
//     protected static array $sensitiveFields = [
//         'account_password',
//     ];

//     /**
//      * Tạo log update nếu có thay đổi thực sự.
//      */
//     public static function logUpdate(
//         string $entityType,
//         Model $model,
//         array $oldData,
//         array $newData,
//         string $source,
//         ?string $category = null,
//         ?int $adminUserId = null,
//         ?int $transactionId = null,
//         ?string $transactionType = null,
//         ?string $note = null
//     ): ?BotHistory {
//         $changedFields = self::buildChangedFields($oldData, $newData);

//         if (empty($changedFields)) {
//             return null;
//         }

//         return BotHistory::create([
//             'entity_type'      => $entityType,
//             'entity_id'        => $model->id,
//             'action'           => 'updated',
//             'source'           => $source,
//             'category'         => $category,
//             'admin_user_id'    => $adminUserId,
//             'transaction_id'   => $transactionId,
//             'transaction_type' => $transactionType,
//             'old_data'         => self::sanitizeSnapshot($oldData),
//             'new_data'         => self::sanitizeSnapshot($newData),
//             'changed_fields'   => $changedFields,
//             'note'             => $note,
//             'ip_address'       => request()?->ip(),
//             'user_agent'       => request()?->userAgent(),
//         ]);
//     }

//     /**
//      * So sánh old/new và chỉ lấy field thay đổi.
//      */
//     protected static function buildChangedFields(array $oldData, array $newData): array
//     {
//         $changed = [];

//         $allKeys = array_unique(array_merge(array_keys($oldData), array_keys($newData)));

//         foreach ($allKeys as $key) {
//             $oldValue = $oldData[$key] ?? null;
//             $newValue = $newData[$key] ?? null;

//             if (!self::valuesAreSame($oldValue, $newValue)) {
//                 if (in_array($key, self::$sensitiveFields, true)) {
//                     $changed[$key] = [
//                         'old' => $oldValue !== null ? '***hidden***' : null,
//                         'new' => $newValue !== null ? '***changed***' : null,
//                     ];
//                 } else {
//                     $changed[$key] = [
//                         'old' => $oldValue,
//                         'new' => $newValue,
//                     ];
//                 }
//             }
//         }

//         return $changed;
//     }

//     /**
//      * Che field nhạy cảm trong snapshot.
//      */
//     protected static function sanitizeSnapshot(array $data): array
//     {
//         foreach (self::$sensitiveFields as $field) {
//             if (array_key_exists($field, $data)) {
//                 $data[$field] = $data[$field] !== null ? '***hidden***' : null;
//             }
//         }

//         return $data;
//     }

//     /**
//      * So sánh giá trị cẩn thận hơn.
//      */
//     protected static function valuesAreSame(mixed $oldValue, mixed $newValue): bool
//     {
//         return $oldValue == $newValue;
//     }
// }


namespace App\Services;

use App\Models\BotHistory;
use Illuminate\Database\Eloquent\Model;

class BotHistoryService
{
    protected static array $sensitiveFields = [
        'account_password',
    ];

    /**
     * Metadata thay đổi ở mỗi lần app đồng bộ, không phải biến động tài sản.
     */
    protected static array $ignoredChangedFields = [
        'updated_by',
        'last_synced_at',
    ];

    public static function logUpdate(
        string $entityType,
        Model $model,
        array $oldData,
        array $newData,
        string $source,
        string $action = 'update',
        ?string $category = null,
        ?int $adminUserId = null,
        ?int $transactionId = null,
        ?string $transactionType = null,
        ?string $note = null
    ): ?BotHistory {
        $changedFields = self::buildChangedFields($oldData, $newData);

        if (empty($changedFields)) {
            return null;
        }

        return BotHistory::create([
            'entity_type'      => $entityType,
            'entity_id'        => $model->id,
            'action'           => $action,
            'source'           => $source,
            'category'         => $category,
            'admin_user_id'    => $adminUserId,
            'transaction_id'   => $transactionId,
            'transaction_type' => $transactionType,
            'old_data'         => self::sanitizeSnapshot($oldData),
            'new_data'         => self::sanitizeSnapshot($newData),
            'changed_fields'   => $changedFields,
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
            if (in_array($key, self::$ignoredChangedFields, true)) {
                continue;
            }

            $oldValue = $oldData[$key] ?? null;
            $newValue = $newData[$key] ?? null;

            if (!self::valuesAreSame($oldValue, $newValue)) {
                if (in_array($key, self::$sensitiveFields, true)) {
                    $changed[$key] = [
                        'old' => $oldValue !== null ? '***hidden***' : null,
                        'new' => $newValue !== null ? '***changed***' : null,
                    ];
                } else {
                    $changed[$key] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
        }

        return $changed;
    }

    protected static function sanitizeSnapshot(array $data): array
    {
        foreach (self::$sensitiveFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] !== null ? '***hidden***' : null;
            }
        }

        return $data;
    }

    protected static function valuesAreSame(mixed $oldValue, mixed $newValue): bool
    {
        return $oldValue == $newValue;
    }
}
