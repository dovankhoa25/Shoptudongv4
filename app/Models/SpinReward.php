<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SpinReward extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'spin_id',
        'reward_type',
        'reward_value',
        'image', // Cache field
        'probability',
    ];

    protected $casts = [
        'probability' => 'float',
    ];

    protected $appends = ['image_url']; // ✅ Thêm accessor vào appends

    // ✅ Accessor để lấy URL ảnh
    public function getImageUrlAttribute()
    {
        // Ưu tiên lấy từ media library
        $mediaUrl = $this->getFirstMediaUrl('image');

        // Nếu không có trong media library, lấy từ cache field
        return $mediaUrl ?: $this->image;
    }

    public function spin()
    {
        return $this->belongsTo(Spin::class);
    }
}
