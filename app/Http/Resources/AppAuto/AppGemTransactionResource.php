<?php

namespace App\Http\Resources\AppAuto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppGemTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'                    => $this->id,
            'user_id'              => $this->user_id,
            'user_name'            => $this->user?->name, // nếu có quan hệ user
            'server_id'            => $this->server_id,
            'server_name'          => $this->server?->name, // nếu có quan hệ server
            'character_name'       => $this->character_name,
            'amount_vnd'           => $this->amount_vnd,
            'gem_qty'              => $this->gem_qty,
            'price_at_transaction' => $this->price_at_transaction,
            'status'               => $this->status,
            'status_text'          => $this->getStatusText(),
            'updated_by'           => $this->updated_by,
            'last_synced_at'       => $this->last_synced_at,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }

    /**
     * Get status text for display
     */
    private function getStatusText(): string
    {
        $statusMap = [
            'pending'    => 'Đang chờ',
            'processing' => 'Đang xử lý',
            'completed'  => 'Hoàn thành',
            'cancelled'  => 'Đã hủy',
            'refunded'   => 'Đã hoàn tiền',
        ];

        return $statusMap[$this->status] ?? $this->status;
    }
}
