<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = [
        'server_id',
        'bot_id',
        'bot_type',
        'asset_type',
        'movement_type',
        'quantity_delta',
        'balance_before',
        'balance_after',
        'transaction_id',
        'transaction_type',
        'idempotency_key',
        'source',
        'admin_user_id',
        'meta',
        'note',
        'occurred_at',
    ];

    protected $casts = [
        'quantity_delta' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];
}
