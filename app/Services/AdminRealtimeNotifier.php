<?php

namespace App\Services;

use App\Events\AdminEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminRealtimeNotifier
{
    public function changed(
        string $resource,
        int|string $resourceId,
        string $action,
        ?string $status,
        string $message,
    ): void {
        try {
            broadcast(new AdminEvent(
                resource: $resource,
                resourceId: $resourceId,
                action: $action,
                status: $status,
                message: $message,
                occurredAt: now()->toIso8601String(),
            ))->toOthers();
        } catch (Throwable $exception) {
            // Realtime chỉ là lớp hiển thị, không được làm hỏng giao dịch chính.
            Log::warning('Admin realtime broadcast failed', [
                'resource' => $resource,
                'resource_id' => $resourceId,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
