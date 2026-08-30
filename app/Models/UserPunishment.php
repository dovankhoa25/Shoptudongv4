<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPunishment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'is_active' => 'boolean', 'revoked_at' => 'datetime',
        ];
    }
}
