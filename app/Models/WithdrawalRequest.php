<?php

namespace App\Models;

use App\Traits\HasUserOwnedScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WithdrawalRequest extends Model
{
    use HasFactory, HasUserOwnedScope;

    // protected $fillable = [
    //     'user_id',
    //     'amount',
    //     'status',
    //     'bank_name',
    //     'bank_account_number',
    //     'bank_account_name',
    //     'note_user',
    //     'note',
    //     'approved_by',
    //     'approved_at',
    //     'rejected_at',
    //     'paid_at',
    // ];
    protected $fillable = [
        'user_id',
        'amount',
        'fee',              // 🆕
        'net_amount',       // 🆕
        'status',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'note_user',
        'note',
        'approved_by',
        'approved_at',
        'rejected_at',
        'paid_at',
        'payment_proof',    // 🆕
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
