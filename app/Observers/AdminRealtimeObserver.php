<?php

namespace App\Observers;

use App\Models\AtmTopup;
use App\Models\Card;
use App\Models\CarotRecharge;
use App\Models\GemTransaction;
use App\Models\GoldTransaction;
use App\Models\Import;
use App\Models\NickOrder;
use App\Models\Order;
use App\Models\RandomOrder;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use App\Services\AdminRealtimeNotifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class AdminRealtimeObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly AdminRealtimeNotifier $realtime) {}

    public function created(Model $model): void
    {
        $this->notify($model, 'created');
    }

    public function updated(Model $model): void
    {
        $watchedFields = array_values(array_intersect(
            ['status', 'bot_id', 'receiver_id', 'updated_by', 'refunded_at', 'processed_at', 'paid_at'],
            array_keys($model->getAttributes()),
        ));

        if ($watchedFields !== [] && ! $model->wasChanged($watchedFields)) {
            return;
        }

        $this->notify($model, $model->wasChanged('status') ? 'status_updated' : 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->notify($model, 'deleted');
    }

    private function notify(Model $model, string $action): void
    {
        $resource = $this->resource($model);

        if ($resource === null) {
            return;
        }

        $status = $model->getAttribute('status');
        $status = is_scalar($status) ? (string) $status : null;
        $resourceId = $model->getKey();

        if (! is_int($resourceId) && ! is_string($resourceId)) {
            return;
        }

        $label = $this->label($resource);
        $message = match ($action) {
            'created' => "{$label} #{$resourceId} vừa được tạo.",
            'status_updated' => "{$label} #{$resourceId} chuyển sang {$this->statusLabel($status)}.",
            'deleted' => "{$label} #{$resourceId} đã bị xóa.",
            default => "{$label} #{$resourceId} vừa được cập nhật.",
        };

        $this->realtime->changed($resource, $resourceId, $action, $status, $message);
    }

    private function resource(Model $model): ?string
    {
        return match (true) {
            $model instanceof GoldTransaction => $model->type === GoldTransaction::TYPE_IMPORT
                ? 'gold_import'
                : 'gold_order',
            $model instanceof GemTransaction => 'gem_order',
            $model instanceof ServiceOrder => 'service_order',
            $model instanceof NickOrder => 'nick_order',
            $model instanceof RandomOrder => 'random_order',
            $model instanceof WithdrawalRequest => 'withdrawal',
            $model instanceof Card => 'card_recharge',
            $model instanceof AtmTopup => 'bank_deposit',
            $model instanceof CarotRecharge => 'carot_recharge',
            $model instanceof Transaction => 'balance_transaction',
            $model instanceof Order => 'legacy_gold_order',
            $model instanceof Import => 'legacy_gold_import',
            default => null,
        };
    }

    private function label(string $resource): string
    {
        return match ($resource) {
            'gold_order', 'legacy_gold_order' => 'Đơn vàng',
            'gold_import', 'legacy_gold_import' => 'Đơn nhập vàng',
            'gem_order' => 'Đơn ngọc',
            'service_order' => 'Đơn dịch vụ',
            'nick_order' => 'Đơn mua nick',
            'random_order' => 'Đơn random',
            'withdrawal' => 'Yêu cầu rút tiền',
            'card_recharge' => 'Thẻ nạp',
            'bank_deposit' => 'Giao dịch ngân hàng',
            'carot_recharge' => 'Đơn nạp Carot',
            'balance_transaction' => 'Giao dịch số dư',
            default => 'Dữ liệu',
        };
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'đang chờ',
            'approved' => 'đã nhận',
            'processing' => 'đang xử lý',
            'completed', 'success', 'paid' => 'hoàn thành',
            'cancelled', 'rejected' => 'đã hủy',
            'refunded' => 'đã hoàn tiền',
            'failed' => 'thất bại',
            default => $status ?: 'trạng thái mới',
        };
    }
}
