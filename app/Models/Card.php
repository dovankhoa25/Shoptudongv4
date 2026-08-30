<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Card extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'declared_value',
        'value',
        'amount_user',
        'amount_api',
        'discount_rate_at_time',
        'code',
        'serial',
        'trans_id',
        'user_id',
        'card_type_id',
        'status',
        'note',
        'loaded_type',
        'difference',
    ];

    protected $casts = [
        'declared_value' => 'integer',
        'value' => 'integer',
        'amount_user' => 'integer',
        'amount_api' => 'integer',
        'difference' => 'decimal:2',
        'discount_rate_at_time' => 'decimal:2',
        'loaded_type' => 'boolean',
    ];

    public function cardType(): BelongsTo
    {
        return $this->belongsTo(CardType::class);
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
