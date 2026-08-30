<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'user_device_id',
        'session_id',
        'oauth_access_token_id',
        'oauth_client_id',
        'ip_address',
        'user_agent',
        'last_activity_at',
        'expires_at',
        'is_revoked',
        'revoked_at',
        'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_revoked' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }
}
