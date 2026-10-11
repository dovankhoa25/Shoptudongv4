<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class AtmTopup extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_transaction_id',
        'gateway',
        'transaction_at',
        'account_number',
        'sub_account',
        'payment_code',
        'content',
        'transfer_type',
        'amount',
        'reference_code',
        'accumulated',
        'description',
        'status',
        'review_reason',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'transaction_at' => 'datetime',
            'amount' => 'integer',
            'accumulated' => 'integer',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function transaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'related');
    }
}
