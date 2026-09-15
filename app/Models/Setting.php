<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\ApiCache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get($key, $default = null)
    {
        $settings = ApiCache::remember('internal:settings', 'all', 3600, function () {
            return self::pluck('value', 'key')->toArray();
        });

        return $settings[$key] ?? $default;
    }

    public static function set($key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        ApiCache::clearGroup('internal:settings');
        // Retire the legacy key too during rolling deployments.
        DB::afterCommit(fn () => Cache::forget('settings'));
    }
}
