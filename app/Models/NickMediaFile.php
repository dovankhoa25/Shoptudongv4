<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NickMediaFile extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['path', 'source_url', 'claim_token'];

    protected $casts = [
        'processing_at' => 'datetime', 'queued_at' => 'datetime', 'available_at' => 'datetime',
    ];
}
