<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpinResult extends Model
{
    protected $fillable = [
        'user_id',
        'spin_id',
        'reward_type',
        'reward_value',
        'reward_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function spin()
    {
        return $this->belongsTo(Spin::class);
    }
}
