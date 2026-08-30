<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NickOrder extends Model
{
    protected $fillable = [
        'nick_id',
        'buyer_id',
        'seller_id',
        'price',
        'commission',
        'status',
    ];

    public function nick()
    {
        return $this->belongsTo(Nick::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
