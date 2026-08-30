<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryTemplate extends Model
{
    protected $fillable = [
        'category_id',
        'features',
        'requirements',
        'instructions',
        'faq',
    ];

    protected $casts = [
        'features' => 'array',
        'requirements' => 'array',
        'instructions' => 'array',
        'faq' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
