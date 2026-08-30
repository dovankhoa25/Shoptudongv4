<?php

namespace App\Models;

use App\Traits\HasReceiverOwnedScope;
use Illuminate\Database\Eloquent\Model;

class ServiceOrder extends Model
{
    use HasReceiverOwnedScope;

    protected $fillable = [
        'service_id',
        'user_id',
        'receiver_id',
        'service_price',
        'account',
        'password',
        'description',
        'field_values_json',
        'status',
    ];

    protected $casts = [
        'field_values_json' => 'array',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function fieldValues()
    {
        return $this->hasMany(ServiceOrderFieldValue::class);
    }

    public function scopeForUserCategories($query, $user)
    {
        // Admin thấy tất cả
        if ($user->hasRole(['admin', 'super-admin']) || $user->can('viewAny', ServiceOrder::class)) {
            return $query;
        }

        // Lấy category IDs mà user có quyền
        $categoryIds = $user->categories()
            ->wherePivot('can_post', true)
            ->pluck('categories.id')
            ->toArray();

        // Nếu user không có category nào, trả về empty
        if (empty($categoryIds)) {
            return $query->whereRaw('1 = 0'); // Không có kết quả nào
        }

        // Filter theo categories
        return $query->whereHas('service.categories', function ($q) use ($categoryIds) {
            $q->whereIn('categories.id', $categoryIds);
        });
    }
}
