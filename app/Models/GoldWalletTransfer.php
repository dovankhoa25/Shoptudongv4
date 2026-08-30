<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoldWalletTransfer extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['completed_at' => 'datetime', 'cancelled_at' => 'datetime']; }
}
