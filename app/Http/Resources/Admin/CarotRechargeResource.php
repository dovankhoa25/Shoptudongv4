<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarotRechargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_code' => $this->transaction_code,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ] : null,
            'account_name' => $this->account_name,
            'server_id' => $this->server_id,
            'server_name' => 'Server ' . $this->server_id,
            'amount' => (int) $this->amount,
            'carot' => (int) $this->carot,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
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
            'pending' => 'Đang chờ xử lý',
            'success' => 'Thành công',
            'failed' => 'Thất bại',
            'cancelled' => 'Đã hủy',
            default => 'Không xác định',
        };
    }
}
