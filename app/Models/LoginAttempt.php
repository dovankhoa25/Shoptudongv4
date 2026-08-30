<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'username',
        'email',
        'provider',
        'ip_address',
        'user_agent',
        'is_success',
        'failure_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_success' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
