<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAccessDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['device_hash'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'expires_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
