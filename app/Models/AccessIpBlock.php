<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessIpBlock extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $invalidate = fn (self $block) => app(\App\Services\AccessIpBlockCache::class)->invalidate($block->getConnection());
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public static function blocks(?string $ip): bool
    {
        return app(\App\Services\AccessIpBlockCache::class)->blocks($ip);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
