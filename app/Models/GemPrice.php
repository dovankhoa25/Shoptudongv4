<?php

// app/Models/GemPrice.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GemPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'multiplier',
        'gift_ticket_multiplier',
        'min_amount',
        'status'
    ];

    protected $casts = [
        'multiplier' => 'decimal:2',
        'gift_ticket_multiplier' => 'decimal:2',
        'min_amount' => 'integer',
        'status' => 'boolean'
    ];

    /**
     * Base price constant (10k VND)
     */
    const BASE_PRICE = 10000;

    /**
     * Get the server that owns the gem price
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Scope for active prices
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Get current multiplier for a server
     */
    public static function getCurrentMultiplier($serverId)
    {
        return self::where('server_id', $serverId)
            ->where('status', true)
            ->latest()
            ->first();
    }

    /**
     * Calculate gems from VND amount
     */
    public function multiplierFor(string $deliveryMethod = 'consignment'): string
    {
        return (string) ($deliveryMethod === 'gift_ticket'
            ? ($this->gift_ticket_multiplier ?? $this->multiplier)
            : $this->multiplier);
    }

    public function calculateGems($vndAmount, string $deliveryMethod = 'consignment')
    {
        return $this->calculateOrderGems($vndAmount, $deliveryMethod);
    }

    /** Keep checkout and calculator consistent, with consignment rounded down. */
    public function calculateOrderGems($vndAmount, string $deliveryMethod = 'consignment'): int
    {
        $baseAmount = bcdiv((string) $vndAmount, (string) self::BASE_PRICE, 8);
        $multipliedAmount = bcmul($baseAmount, $this->multiplierFor($deliveryMethod), 8);
        $gems = bcmul($multipliedAmount, '10', 8);

        return $deliveryMethod === 'gift_ticket'
            ? (int) ceil((float) $gems)
            : (int) bcdiv($gems, '1', 0);
    }

    /**
     * Calculate VND from gems amount
     */
    public function calculateVnd($gemsAmount, string $deliveryMethod = 'consignment')
    {
        // Số tiền = (Số ngọc / (multiplier * 10)) * 10,000
        return ceil(($gemsAmount / ($this->multiplierFor($deliveryMethod) * 10)) * self::BASE_PRICE);
    }

    /**
     * Get formatted multiplier display
     */
    public function getMultiplierDisplayAttribute()
    {
        return 'x' . number_format($this->multiplier, 1);
    }

    /**
     * Get gems per 10k
     */
    public function getGemsPerBaseAttribute()
    {
        return $this->multiplier * 10;
    }
}
