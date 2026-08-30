<?php

// app/Models/GemBot.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GemBot extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'account_name',
        'account_password',
        'server_id',
        'server_game_id',
        'gem_qty',
        'map_name',
        'map_id',
        'area_number',
        'coordinates',
        'proxy',
        'updated_by',
        'last_synced_at',
        'status'
    ];

    protected $casts = [
        'gem_qty' => 'integer',
        'status' => 'boolean',
        'last_synced_at' => 'datetime'
    ];

    protected $hidden = [
        'account_password'
    ];

    /**
     * Get the server that owns the bot
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }


    public function scopeWithAvailableGems($query, $minGems = 1)
    {
        return $query->where('gem_qty', '>=', $minGems);
    }

    /**
     * Get bot by server with most gems
     */
    public static function getBotWithMostGems($serverId)
    {
        return self::where('server_id', $serverId)
            ->where('status', true)
            ->orderBy('gem_qty', 'desc')
            ->first();
    }

    /**
     * Update gem quantity
     */
    public function updateGemQuantity($amount, $operation = 'subtract')
    {
        if ($operation === 'subtract') {
            $this->gem_qty = max(0, $this->gem_qty - $amount);
        } else {
            $this->gem_qty += $amount;
        }

        $this->save();
        return $this;
    }

    /**
     * Sync from app
     */
    public function syncFromApp($gemQty)
    {
        $this->update([
            'gem_qty' => $gemQty,
            'updated_by' => 'app',
            'last_synced_at' => now()
        ]);
    }


    public function historySnapshot(): array
    {
        return $this->only([
            'name',
            'account_name',
            'account_password',
            'server_id',
            'server_game_id',
            'gem_qty',
            'map_name',
            'map_id',
            'area_number',
            'coordinates',
            'proxy',
            'updated_by',
            'last_synced_at',
            'status',
        ]);
    }
}
