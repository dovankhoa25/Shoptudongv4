<?php

namespace App\Models;

use App\Traits\HasUserOwnedScope;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Nick extends Model implements HasMedia
{

    use HasUserOwnedScope, InteractsWithMedia;


    protected $fillable = [
        'account_name',
        'account_password',
        'price',
        'description',
        'image',
        'listing_type',
        'category_id',
        'user_id',
        'status',
        'attribute_cache_json'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attributes()
    {
        return $this->belongsToMany(Attribute::class, 'nick_attributes')
            ->withPivot('attribute_option_id')
            ->withTimestamps();
    }

    public function attributeOptions()
    {
        return $this->hasManyThrough(
            AttributeOption::class,
            NickAttribute::class,
            'nick_id',
            'id',
            'id',
            'attribute_option_id'
        );
    }
}
