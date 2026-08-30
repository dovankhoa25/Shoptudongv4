<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarotRecharge extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'account_name',
        'server_id',
        'amount',
        'carot',
        'transaction_code',
        'status',
        'message',
        'api_response',
        'processed_at',
    ];

    protected $casts = [
        'server_id' => 'integer',
        'amount' => 'integer',
        'carot' => 'integer',
        'api_response' => 'array',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
