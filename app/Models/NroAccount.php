<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NroAccount extends Model implements \Spatie\MediaLibrary\HasMedia
{
    use SoftDeletes, \Spatie\MediaLibrary\InteractsWithMedia;

    protected $guarded = [];
    protected $hidden = ['game_password'];

    protected function casts(): array
    {
        return ['login_sale_blocked' => 'boolean', 'shop_hidden' => 'boolean', 'delivery_activity' => 'array', 'auto_publish' => 'boolean', 'publish_config' => 'array', 'locked_until' => 'datetime', 'game_password' => 'encrypted', 'last_synced_at' => 'datetime'];
    }
}
