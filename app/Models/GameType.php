<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class GameType extends Model
{
    use HasSlug;

    protected $fillable = ['name', 'slug', 'icon', 'sort_order'];



    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }


    public function categories()
    {
        return $this->hasMany(Category::class);
    }
}
