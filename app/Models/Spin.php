<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Spin extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'category_id',
        'name',
        'image', // Cache field
        'type',
        'price_per_turn',
        'total_slots',
        'is_public',
        'sort_order',
        'description',
    ];

    protected $casts = [
        'price_per_turn' => 'decimal:0',
        'is_public' => 'boolean',
        'total_slots' => 'integer',
        'sort_order' => 'integer',
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

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function rewards()
    {
        return $this->hasMany(SpinReward::class);
    }

    public function results()
    {
        return $this->hasMany(SpinResult::class);
    }

    public function tickets()
    {
        return $this->hasMany(SpinTicket::class);
    }
}
