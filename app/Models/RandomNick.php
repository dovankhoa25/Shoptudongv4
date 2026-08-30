<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class RandomNick extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'random_box_id',
        'account',
        'password',
        'description',
        'status',
        'user_id',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    // Relationships
    public function randomBox(): BelongsTo
    {
        return $this->belongsTo(RandomBox::class);
    }

    public function randomOrder(): HasOne
    {
        return $this->hasOne(RandomOrder::class);
    }

    // Media collections
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile();
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeTaken($query)
    {
        return $query->where('status', 'taken');
    }

    public function scopeByRandomBox($query, $randomBoxId)
    {
        return $query->where('random_box_id', $randomBoxId);
    }

    // Accessors
    public function getImageUrlAttribute(): ?string
    {
        // Ưu tiên ảnh riêng của nick
        $nickImage = $this->getFirstMediaUrl('image');
        if ($nickImage) {
            return $nickImage;
        }

        // Fallback sang ảnh của random box
        return $this->randomBox?->getFirstMediaUrl('image');
    }

    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            'available' => 'Có sẵn',
            'taken' => 'Đã bán',
            'deleted' => 'Đã xóa',
            default => 'Không xác định'
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'available' => 'success',
            'taken' => 'warning',
            'deleted' => 'danger',
            default => 'default'
        };
    }

    // Methods
    public function markAsTaken(): bool
    {
        return $this->update(['status' => 'taken']);
    }

    public function markAsAvailable(): bool
    {
        return $this->update(['status' => 'available']);
    }

    public function markAsDeleted(): bool
    {
        return $this->update(['status' => 'deleted']);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function isTaken(): bool
    {
        return $this->status === 'taken';
    }

    public function isDeleted(): bool
    {
        return $this->status === 'deleted';
    }
}
