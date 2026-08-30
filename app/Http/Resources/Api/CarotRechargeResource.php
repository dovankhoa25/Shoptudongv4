<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarotRechargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_code' => $this->transaction_code,
            'account' => [
                'name' => $this->account_name,
                'server_id' => $this->server_id,
                'server_name' => 'Server ' . $this->server_id,
            ],
            'amount' => [
                'value' => $this->amount,
                'formatted' => number_format((int) $this->amount) . ' VND',
            ],
            'carot' => [
                'value' => $this->carot,
                'formatted' => number_format((int) $this->carot),
            ],
            'status' => [
                'value' => $this->status,
                'label' => $this->statusLabel(),
            ],
            'message' => $this->message,
            'api_response' => $this->api_response,
            'processed_at' => $this->processed_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Dang cho xu ly',
            'success' => 'Thanh cong',
            'failed' => 'That bai',
            'cancelled' => 'Da huy',
            default => 'Khong xac dinh',
        };
    }
}
