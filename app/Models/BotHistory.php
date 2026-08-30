<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BotHistory extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'source',
        'category',
        'admin_user_id',
        'transaction_id',
        'transaction_type',
        'old_data',
        'new_data',
        'changed_fields',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'changed_fields' => 'array',
    ];

    public function adminUser()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
