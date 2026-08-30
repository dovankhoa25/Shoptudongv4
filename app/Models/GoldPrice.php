<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoldPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'price',
        'import_price',
        'status',
    ];

    // 🔗 Thuộc về Server
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
