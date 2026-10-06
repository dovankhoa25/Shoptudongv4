<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RandomOrder extends Model
{
    protected $fillable = [
        'user_id',
        'random_nick_id',
        'price',
        'random_box_id',
        'result',
        'win_rate_snapshot',
        'lose_reason',
        'selected_slot',
        'purchase_key',
        'purchase_fingerprint',
    ];


    protected $casts = [
        'price' => 'decimal:0',
        'win_rate_snapshot' => 'decimal:2',
        'selected_slot' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function randomBox(): BelongsTo
    {
        return $this->belongsTo(RandomBox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship với RandomNick
     */
    public function randomNick(): BelongsTo
    {
        return $this->belongsTo(RandomNick::class)->withTrashed();
    }
}
