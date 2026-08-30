<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'name',
        'default_price',
        'original_price',
        'description',
        'status',
        'is_popular',
        'processing_time',
        'warranty',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_popular' => 'boolean',
    ];

    public function fields()
    {
        return $this->belongsToMany(Field::class, 'field_service');
    }

    public function orders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_service', 'service_id', 'category_id');
    }
}
