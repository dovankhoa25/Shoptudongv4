<?php

namespace App\Services\TradingV3;

use Illuminate\Database\Eloquent\Model;

/** Admin decisions retain the original game checkpoint; they never rewrite bot inventory. */
final class ManualOrderResolution
{
    public static function attributes(Model $order, string $status, ?int $adminId, ?string $reason = null): array
    {
        if (! in_array($status, ['completed', 'cancelled', 'refunded'], true)) {
            return [];
        }

        $data = $order->delivery_data ?? [];
        $data['manual_resolution'] = [
            'status' => $status,
            'admin_id' => $adminId,
            'resolved_at' => now()->toIso8601String(),
            'previous_status' => $order->status,
            'previous_phase' => $order->delivery_phase,
            'reason' => $reason,
        ];

        return [
            'delivery_data' => $data,
            'delivery_phase' => 'manual_'.$status,
            ...($status === 'completed' ? ['completed_at' => $order->completed_at ?? now()] : []),
        ];
    }
}
