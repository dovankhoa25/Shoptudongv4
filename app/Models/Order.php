<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'server_id',
        'character_name',
        'amount_vnd',
        'gold_qty',
        'gold_bar_qty',
        'pure_gold_qty',
        'price_at_order',
        'status',
        'bot_id',
        'updated_by'

    ];

    // 🔗 Order thuộc User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // 🔗 Order thuộc Server
    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    // 🔗 Order gán cho Bot
    public function bot()
    {
        return $this->belongsTo(Bot::class);
    }

    // 🔗 Order có nhiều Transactions
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
