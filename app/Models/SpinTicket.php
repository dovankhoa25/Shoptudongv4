<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpinTicket extends Model
{
    protected $fillable = [
        'user_id',
        'spin_id',
        'turns_remaining',
    ];

    protected $casts = [
        'turns_remaining' => 'integer',
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
