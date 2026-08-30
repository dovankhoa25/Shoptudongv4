<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarotRechargeStatistic extends Model
{
    use HasFactory;

    public const TYPE_DAILY = 'daily';
    public const TYPE_MONTHLY = 'monthly';
    public const TYPE_YEARLY = 'yearly';

    protected $fillable = [
        'type',
        'stat_date',
        'user_id',
        'server_id',
        'total_transactions',
        'success_transactions',
        'failed_transactions',
        'total_amount',
        'total_carot',
    ];

    protected $casts = [
        'stat_date' => 'date',
        'user_id' => 'integer',
        'server_id' => 'integer',
        'total_transactions' => 'integer',
        'success_transactions' => 'integer',
        'failed_transactions' => 'integer',
        'total_amount' => 'integer',
        'total_carot' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
