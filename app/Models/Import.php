<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Import extends Model
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
        'import_price_at_order',
        'status',
        'bot_id',
        'updated_by'

    ];

    // 🔗 Import thuộc User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // 🔗 Import thuộc Server
    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    // 🔗 Import gán cho Bot
    public function bot()
    {
        return $this->belongsTo(Bot::class);
    }

    // 🔗 Import có nhiều Transactions
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
