<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Auth\Access\Response;

class WithdrawalRequestPolicy
{
    /**
     * Xem được nếu là người tạo hoặc admin.
     */
    public function view(User $user, WithdrawalRequest $withdrawal): bool
    {
        return $user->id === $withdrawal->user_id || $user->hasRole('admin');
    }

    /**
     * Duyệt nếu là admin.
     */
    public function approve(User $user, WithdrawalRequest $withdrawal): bool
    {
        return $user->hasRole('admin') || $user->id === $withdrawal->user_id;
    }

    /**
     * Từ chối nếu là admin.
     */
    public function reject(User $user, WithdrawalRequest $withdrawal): bool
    {
        return $user->hasRole('admin') || $user->id === $withdrawal->user_id;
    }

    /**
     * Đánh dấu đã thanh toán - chỉ admin.
     */
    public function markPaid(User $user, WithdrawalRequest $withdrawal): bool
    {
        return $user->hasRole('admin');
    }
}
