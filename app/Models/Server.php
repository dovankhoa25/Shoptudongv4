<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_view',
        'ip',
        'port',
        'status',
    ];

    // 🔗 1 Server có nhiều GoldPrices
    public function goldPrices()
    {
        return $this->hasMany(GoldPrice::class);
    }

    // 🔗 1 Server có nhiều Bots
    public function bots()
    {
        return $this->hasMany(Bot::class);
    }

    // 🔗 1 Server có nhiều Orders
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // 🔗 1 Server có nhiều Imports
    public function imports()
    {
        return $this->hasMany(Import::class);
    }


    public function scopeActive($query)
    {
        return $query->where('status', true);
    }



    /**
     * Get the gem prices for the server
     */
    public function gemPrices(): HasMany
    {
        return $this->hasMany(GemPrice::class);
    }

    /**
     * Get the current gem price for the server
     */
    public function currentGemPrice(): HasOne
    {
        return $this->hasOne(GemPrice::class)
            ->where('status', true)
            ->latest();
    }

    /**
     * Get the gem bots for the server
     */
    public function gemBots(): HasMany
    {
        return $this->hasMany(GemBot::class);
    }

    /**
     * Get active gem bots for the server
     */
    public function activeGemBots(): HasMany
    {
        return $this->hasMany(GemBot::class)
            ->where('status', true);
    }

    /**
     * Get the gem transactions for the server
     */
    public function gemTransactions(): HasMany
    {
        return $this->hasMany(GemTransaction::class);
    }

    /**
     * Get total available gems in server
     */
    public function getTotalAvailableGems()
    {
        return $this->gemBots()
            ->where('status', true)
            ->sum('gem_qty');
    }

    /**
     * Check if server has enough gems for transaction
     */
    public function hasEnoughGems($requiredGems)
    {
        return $this->getTotalAvailableGems() >= $requiredGems;
    }
}
