<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class RandomBox extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'category_id',
        'name',
        'price',
        'image',
        'is_public',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:0',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function randomNicks(): HasMany
    {
        return $this->hasMany(RandomNick::class);
    }

    public function availableNicks(): HasMany
    {
        return $this->hasMany(RandomNick::class)
            ->where('status', 'available');
    }

    public function takenNicks(): HasMany
    {
        return $this->hasMany(RandomNick::class)
            ->where('status', 'taken');
    }

    public function randomOrders(): HasMany
    {
        return $this->hasMany(RandomOrder::class);
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_public', true)
            ->whereHas('category', function ($q) {
                $q->where('is_public', true)
                    ->where('status', 'active');
            });
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    // Accessors & Mutators
    public function getPriceFormattedAttribute(): string
    {
        return number_format($this->price) . 'đ';
    }



    public function getStatusTextAttribute(): string
    {
        return $this->is_public ? 'Công khai' : 'Đã ẩn';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->is_public ? 'success' : 'danger';
    }

    // Methods
    public function getTotalNicksCount(): int
    {
        return $this->randomNicks()->count();
    }

    public function getAvailableNicksCount(): int
    {
        return $this->availableNicks()->count();
    }

    public function getTakenNicksCount(): int
    {
        return $this->takenNicks()->count();
    }

    public function hasAvailableNicks(): bool
    {
        return $this->getAvailableNicksCount() > 0;
    }

    public function getRandomAvailableNick(): ?RandomNick
    {
        return $this->availableNicks()->inRandomOrder()->first();
    }
}
