<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAuthProvider extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'provider_email',
        'provider_username',
        'avatar',
        'is_enabled',
        'last_login_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
