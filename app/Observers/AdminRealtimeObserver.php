<?php

namespace App\Observers;

use App\Models\AtmTopup;
use App\Models\Bot;
use App\Models\Card;
use App\Models\CarotRecharge;
use App\Models\GemTransaction;
use App\Models\GemBot;
use App\Models\GemPrice;
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
        // Query-builder balance writes still produce a ledger row, even without a User model event.
        if($model instanceof Transaction && $model->user_id) {
            $notify=fn()=>app(\App\Services\UserRealtimeNotifier::class)->balanceChanged((int)$model->user_id,(int)$model->amount,0,'Số dư của bạn đã được cập nhật.');
            \Illuminate\Support\defer($notify,'ledger-balance:'.$model->user_id);
            if(app()->runningInConsole())app()->terminating($notify);
        }
    }

    public function updated(Model $model): void
    {
        if(array_diff(array_keys($model->getChanges()),['updated_at'])===[])return;

        // A worker renews its lease often. Refresh stock/configuration only when the
        // durable values shown on the page change, not on leases or sync timestamps.
        if (($model instanceof Bot || $model instanceof GemBot) && !$model->wasChanged([
            'name', 'account_name', 'account_password', 'type', 'server_id', 'server_game_id',
            'gold_qty', 'gold_bar_qty', 'gem_qty', 'gift_ticket_qty', 'consign_item_qty',
            'map_name', 'map_id', 'area_number', 'coordinates', 'proxy', 'status',
        ])) return;
        if ($model instanceof GemPrice && !$model->wasChanged([
            'server_id', 'multiplier', 'gift_ticket_multiplier', 'min_amount', 'status',
        ])) return;

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
        $status = is_bool($status) ? ($status ? '1' : '0') : (is_scalar($status) ? (string) $status : null);
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
            $model instanceof Bot => 'gold_bot',
            $model instanceof GemBot => 'gem_bot',
            $model instanceof GemPrice => 'gem_price',
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
            'gold_bot' => 'Bot vàng',
            'gem_bot' => 'Bot ngọc',
            'gem_price' => 'Giá ngọc',
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
            '1' => 'bật',
            '0' => 'tắt',
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
