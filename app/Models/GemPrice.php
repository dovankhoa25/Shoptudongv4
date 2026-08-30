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
        'status'
    ];

    protected $casts = [
        'multiplier' => 'decimal:2',
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
    public function calculateGems($vndAmount)
    {
        // Số ngọc = (Số tiền / 10,000) * multiplier * 10
        return floor(($vndAmount / self::BASE_PRICE) * $this->multiplier * 10);
    }

    /**
     * Calculate VND from gems amount
     */
    public function calculateVnd($gemsAmount)
    {
        // Số tiền = (Số ngọc / (multiplier * 10)) * 10,000
        return ceil(($gemsAmount / ($this->multiplier * 10)) * self::BASE_PRICE);
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
