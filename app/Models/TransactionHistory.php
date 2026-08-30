<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionHistory extends Model
{
    protected $fillable = [
        'transaction_type',
        'transaction_id',
        'action',
        'source',
        'admin_user_id',
        'bot_id',
        'bot_type',
        'old_data',
        'new_data',
        'changed_fields',
        'meta',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'changed_fields' => 'array',
        'meta' => 'array',
    ];
}
