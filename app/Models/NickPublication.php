<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class NickPublication extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected $hidden = ['payload', 'request_hash', 'active_key'];

    protected $casts = ['payload' => 'encrypted:array'];

    public function files(): HasMany
    {
        return $this->hasMany(NickMediaFile::class, 'publication_id')->orderBy('position');
    }

    public function failedFiles(): HasMany
    {
        return $this->files()->where('status', 'failed');
    }
}
