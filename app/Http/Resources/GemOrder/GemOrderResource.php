<?php

// app/Http/Resources/GemOrder/GemOrderResource.php

namespace App\Http\Resources\GemOrder;

use Illuminate\Http\Resources\Json\JsonResource;

class GemOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user_id,
                'username' => $this->whenLoaded('user', function () {
                    return $this->user->username;
                }),
                'email' => $this->whenLoaded('user', function () {
                    return $this->user->email;
                }),
            ],
            'server' => [
                'id' => $this->server_id,
                'name' => $this->whenLoaded('server', function () {
                    return $this->server->name;
                }),
            ],
            'character_name' => $this->character_name,
            'delivery_method' => $this->delivery_method,
            'delivery_method_label' => $this->delivery_method === 'gift_ticket' ? 'Vé tặng ngọc' : 'Đồ ký gửi',
            'bot_id' => $this->bot_id,
            'processing_version' => $this->processing_version,
            'delivery_phase' => $this->delivery_phase,
            'delivery_phase_label' => match ($this->delivery_phase) {
                'waiting_customer' => 'Chờ khách giao dịch',
                'giving_item' => 'Đang giao đồ ký',
                'waiting_consignment' => 'Chờ khách ký gửi đồ',
                'gifting' => 'Đang tặng ngọc bằng vé',
                'buying' => 'Đang mua đồ ký',
                'waiting_login' => 'Chờ bot kết nối',
                'review_required', 'reconciling' => 'Đang đối chiếu tồn và giao dịch',
                'syncing' => 'Chờ web xác nhận',
                'completed' => 'Đã giao đủ ngọc',
                'ready' => 'Sẵn sàng xử lý',
                default => $this->delivery_phase ? 'Đang xử lý' : null,
            },
            'bot_gem_spent_qty' => $this->bot_gem_spent_qty,
            'delivered_gem_qty' => $this->delivered_gem_qty,
            'tickets_used_qty' => $this->tickets_used_qty,
            'equipment_delivered' => (bool) ($this->delivery_data['delivery_item']['verified'] ?? false),
            'amount_vnd' => $this->amount_vnd,
            'amount_vnd_formatted' => number_format($this->amount_vnd, 0, ',', '.').' VND',
            'gem_qty' => $this->gem_qty,
            'gem_qty_formatted' => number_format($this->gem_qty, 0, ',', '.'),
            'price_at_transaction' => $this->price_at_transaction,
            'multiplier_display' => 'x'.rtrim(rtrim(number_format((float) $this->price_at_transaction, 2, ',', ''), '0'), ','),
            'price_formatted' => number_format($this->price_at_transaction, 0, ',', '.').' VND/ngọc',
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'can_refund' => $this->status === 'pending'
                && $this->refunded_at === null
                && (int) $this->amount_vnd > 0,
            'can_complete' => $this->status === 'processing' && !$this->processing_token && empty($this->delivery_data['claim']),
            'can_cancel' => $this->status === 'pending' || ($this->status === 'processing' && !$this->processing_token && empty($this->delivery_data['claim'])),
            'can_update_status' => $this->refunded_at === null
                && !($this->status === 'processing' && ($this->processing_token || !empty($this->delivery_data['claim'])))
                && in_array($this->status, ['pending', 'processing', 'cancelled']),
            'is_timeout_cancellation' => $this->status === 'cancelled'
                && $this->cancel_requested_at !== null
                && $this->refunded_at === null,
            'updated_by' => $this->updated_by,
            'last_synced_at' => $this->last_synced_at?->format('d/m/Y H:i:s'),
            'last_synced_at_human' => $this->last_synced_at?->diffForHumans(),
            'cancel_requested_at' => $this->cancel_requested_at?->format('d/m/Y H:i:s'),
            'cancel_requested_at_human' => $this->cancel_requested_at?->diffForHumans(),
            'refunded_at' => $this->refunded_at?->format('d/m/Y H:i:s'),
            'refunded_at_human' => $this->refunded_at?->diffForHumans(),
            'created_at' => $this->created_at,
            'created_at_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at,
            'updated_at_human' => $this->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Get status label in Vietnamese
     */
    private function getStatusLabel()
    {
        $labels = [
            'pending' => 'Chờ xử lý',
            'processing' => 'Đang xử lý',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
            'refunded' => 'Đã hoàn tiền',
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Get status color for UI
     */
    private function getStatusColor()
    {
        $colors = [
            'pending' => 'yellow',
            'processing' => 'blue',
            'completed' => 'green',
            'cancelled' => 'red',
            'refunded' => 'purple',
        ];

        return $colors[$this->status] ?? 'gray';
    }
}
