<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarotRechargeStatisticResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'stat_date' => $this->stat_date?->toDateString(),
            'scope' => [
                'user_id' => $this->user_id,
                'server_id' => $this->server_id,
                'server_name' => $this->server_id ? 'Server ' . $this->server_id : 'Tat ca server',
            ],
            'transactions' => [
                'total' => $this->total_transactions,
                'success' => $this->success_transactions,
                'failed' => $this->failed_transactions,
            ],
            'amount' => [
                'total' => $this->total_amount,
                'formatted' => number_format((int) $this->total_amount) . ' VND',
            ],
            'carot' => [
                'total' => $this->total_carot,
                'formatted' => number_format((int) $this->total_carot),
            ],
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
