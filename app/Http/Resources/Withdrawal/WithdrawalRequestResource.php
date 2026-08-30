<?php

namespace App\Http\Resources\Withdrawal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user?->id,
                'username' => $this->user?->username,
                'email' => $this->user?->email, // 🆕 Thêm email
                'roles' => $this->user?->roles->pluck('name') ?? [],
            ],
            'amount' => $this->amount,

            // 🆕 Thêm các trường mới
            'fee' => $this->fee ?? '0',
            'net_amount' => $this->net_amount ?? '0',

            'status' => $this->status,

            'bank_name' => $this->bank_name,
            'bank_account_number' => $this->bank_account_number,
            'bank_account_name' => $this->bank_account_name,

            'note_user' => $this->note_user,
            'note' => $this->note, // 🔧 Sửa từ note_admin thành note để khớp với database

            // 🆕 Thêm payment_proof
            'payment_proof' => $this->payment_proof ? asset('storage/' . $this->payment_proof) : null,

            'approver' => $this->approver ? [
                'id' => $this->approver->id,
                'username' => $this->approver->username,
            ] : null,

            'approved_at' => $this->approved_at,
            'rejected_at' => $this->rejected_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at, // 🆕 Thêm updated_at
        ];
    }
}
