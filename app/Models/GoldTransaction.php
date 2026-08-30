<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoldTransaction extends Model
{
    use HasFactory;

    public const TYPE_IMPORT = 'import';

    public const TYPE_ORDER = 'order';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $hidden = [
        'cancel_requested_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_vnd' => 'integer',
            'gold_qty' => 'integer',
            'gold_bar_qty' => 'integer',
            'pure_gold_qty' => 'integer',
            'price_at_transaction' => 'integer',
            'last_synced_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
