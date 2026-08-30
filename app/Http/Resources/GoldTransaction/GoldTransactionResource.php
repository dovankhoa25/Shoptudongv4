<?php

namespace App\Http\Resources\GoldTransaction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoldTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'email' => $this->user->email,
                ];
            }),
            'server' => $this->whenLoaded('server', function () {
                return [
                    'id' => $this->server->id,
                    'name' => $this->server->name,
                ];
            }),
            'bot' => $this->whenLoaded('bot', function () {
                return [
                    'id' => $this->bot->id,
                    'name' => $this->bot->name,
                ];
            }),
            'character_name' => $this->character_name,
            'type' => $this->type,
            'amount_vnd' => $this->amount_vnd,
            'amount_vnd_formatted' => number_format($this->amount_vnd, 0, ',', '.') . ' VND',
            'gold_qty' => $this->gold_qty,
            'gold_qty_formatted' => number_format($this->gold_qty, 0, ',', '.'),
            'gold_bar_qty' => $this->gold_bar_qty,
            'gold_bar_qty_formatted' => number_format($this->gold_bar_qty, 0, ',', '.'),
            'pure_gold_qty' => $this->pure_gold_qty,
            'pure_gold_qty_formatted' => number_format($this->pure_gold_qty, 0, ',', '.'),
            'price_at_transaction' => $this->price_at_transaction,
            'price_formatted' => number_format($this->price_at_transaction, 0, ',', '.'),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'can_refund' => $this->type === 'order'
                && in_array($this->status, ['pending', 'processing'], true),
            'can_complete' => $this->status === 'processing',
            'can_cancel' => in_array($this->status, ['pending', 'processing']),
            'can_process' => $this->status === 'pending',
            'updated_by' => $this->updated_by,
            'cancel_reason' => $this->cancel_reason,
            'admin_note' => $this->admin_note,
            'last_synced_at' => $this->last_synced_at,
            'last_synced_at_human' => $this->last_synced_at,
            'processed_at' => $this->processed_at,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
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
            'failed' => 'Thất bại',
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
            'failed' => 'red',
        ];

        return $colors[$this->status] ?? 'gray';
    }
}
