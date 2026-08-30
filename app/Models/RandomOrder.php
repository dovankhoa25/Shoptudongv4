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
    ];


    protected $casts = [
        'price' => 'decimal:0',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship với RandomNick
     */
    public function randomNick(): BelongsTo
    {
        return $this->belongsTo(RandomNick::class);
    }
}
