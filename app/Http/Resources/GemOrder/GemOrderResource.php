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
            'amount_vnd' => $this->amount_vnd,
            'amount_vnd_formatted' => number_format($this->amount_vnd, 0, ',', '.').' VND',
            'gem_qty' => $this->gem_qty,
            'gem_qty_formatted' => number_format($this->gem_qty, 0, ',', '.'),
            'price_at_transaction' => $this->price_at_transaction,
            'price_formatted' => number_format($this->price_at_transaction, 0, ',', '.').' VND/ngọc',
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'can_refund' => $this->status !== 'refunded'
                && $this->refunded_at === null
                && (int) $this->amount_vnd > 0,
            'can_complete' => $this->status === 'processing',
            'can_cancel' => in_array($this->status, ['pending', 'processing']),
            'can_update_status' => $this->refunded_at === null
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
