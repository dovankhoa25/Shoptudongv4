<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Category extends Model implements HasMedia
{

    use InteractsWithMedia, HasSlug;

    protected $fillable = [
        'game_type_id',
        'name',
        'slug',
        // 'image',
        'template',
        'is_public',
        'status',
        'sort_order',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }
    public function gameType()
    {
        return $this->belongsTo(GameType::class);
    }

    public function nicks()
    {
        return $this->hasMany(Nick::class);
    }

    public function attributes()
    {
        return $this->belongsToMany(Attribute::class)
            ->withTimestamps();
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'category_service', 'category_id', 'service_id');
    }

    public function categoryTemplate()
    {
        return $this->hasOne(CategoryTemplate::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'category_user')
            ->withPivot('can_post')
            ->withTimestamps();
    }
}
