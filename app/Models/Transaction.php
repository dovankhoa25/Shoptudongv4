<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    public const TYPE_ADMIN_CREDIT = 'admin_credit';

    public const TYPE_ADMIN_DEBIT = 'admin_debit';

    public const TYPE_CARD_DEPOSIT = 'card_deposit';

    public const TYPE_BANK_DEPOSIT = 'bank_deposit';

    public const TYPE_GOLD_ORDER_REFUND = 'gold_order_refund';

    public const TYPE_GOLD_IMPORT_CREDIT = 'gold_import_credit';

    public const TYPE_GEM_ORDER_REFUND = 'gem_order_refund';

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_ADMIN_CREDIT,
            self::TYPE_ADMIN_DEBIT,
            self::TYPE_CARD_DEPOSIT,
            self::TYPE_BANK_DEPOSIT,
            self::TYPE_GOLD_ORDER_REFUND,
            self::TYPE_GOLD_IMPORT_CREDIT,
            self::TYPE_GEM_ORDER_REFUND,
        ];
    }

    protected $fillable = [
        'user_id',
        'performed_by',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'related_id',
        'related_type',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by')->withTrashed();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function getOldBalanceAttribute(): ?int
    {
        return $this->balance_before === null ? null : (int) $this->balance_before;
    }

    public function getNewBalanceAttribute(): ?int
    {
        return $this->balance_after === null ? null : (int) $this->balance_after;
    }
}
