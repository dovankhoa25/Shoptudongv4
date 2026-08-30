<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bot extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'account_name',
        'account_password',
        'type',
        'server_id',
        'server_game_id',
        'gold_bar_qty',
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
}
