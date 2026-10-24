<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bot extends Model
{
    use HasFactory;
    protected $hidden = ['stock_version'];
    protected $casts = ['stock_version'=>'integer', 'gold_qty'=>'integer', 'gold_bar_qty'=>'integer', 'nrj_gold_bar_qty'=>'integer', 'status'=>'boolean', 'last_synced_at'=>'datetime'];

    protected $fillable = [
        'name',
        'account_name',
        'account_password',
        'type',
        'server_id',
        'server_game_id',
        'gold_bar_qty',
        'nrj_gold_bar_qty',
        'gold_qty',
        'map_name',
        'map_id',
        'area_number',
        'coordinates',
        'proxy',
        'status',
        'updated_by'
    ];

    // 🔗 Bot thuộc Server
    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    // 🔗 Bot thuộc Server _ id
    public function server_game_login()
    {
        return $this->belongsTo(ServerGameLogin::class);
    }

    public function loginServer()
    {
        return $this->belongsTo(ServerGameLogin::class, 'server_game_id');
    }

    // 🔗 Bot có thể được gán cho nhiều Orders
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // 🔗 Bot có thể được gán cho nhiều Imports
    public function imports()
    {
        return $this->hasMany(Import::class);
    }

    // 🔗 Bot liên quan Transactions
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeWithRole($query, string $role)
    {
        $role = trim($role);
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $role);
        return $query->where(function ($q) use ($role, $escaped) {
            $q->whereRaw("REPLACE(type, ' ', '') = ?", [$role])
                ->orWhereRaw("REPLACE(type, ' ', '') LIKE ? ESCAPE '!'", [$escaped.',%'])
                ->orWhereRaw("REPLACE(type, ' ', '') LIKE ? ESCAPE '!'", ['%,'.$escaped])
                ->orWhereRaw("REPLACE(type, ' ', '') LIKE ? ESCAPE '!'", ['%,'.$escaped.',%']);
        });
    }

    public function isChecker(): bool
    {
        return in_array('checker', array_map('trim', explode(',', strtolower((string) $this->type))), true);
    }

    public function scopeWithoutChecker($query)
    {
        // Protect public/financial selection even if a mixed role was imported directly.
        return $query->whereRaw("LOWER(REPLACE(type, ' ', '')) != ?", ['checker'])
            ->whereRaw("LOWER(REPLACE(type, ' ', '')) NOT LIKE ?", ['checker,%'])
            ->whereRaw("LOWER(REPLACE(type, ' ', '')) NOT LIKE ?", ['%,checker'])
            ->whereRaw("LOWER(REPLACE(type, ' ', '')) NOT LIKE ?", ['%,checker,%']);
    }

}
