<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuckyNumberBet extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['placed_at' => 'datetime', 'settled_at' => 'datetime']; }
}
