<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CategoryController;
use App\Models\Category;
use App\Models\GameType;
use App\Services\FrontendClientRegistry;
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_batches_media_and_cache_hit_runs_no_queries(): void
    {
        $game = GameType::create(['name' => 'Game', 'sort_order' => 0]);
        for ($i = 0; $i < 20; $i++) {
            $category = Category::create(['game_type_id' => $game->id, 'name' => 'Category '.$i, 'sort_order' => $i]);
            $category->media()->create([
                'collection_name' => 'image', 'name' => 'cover', 'file_name' => 'cover.jpg',
                'mime_type' => 'image/jpeg',
                'disk' => 'public', 'size' => 100, 'manipulations' => [],
                'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
            ]);
        }
        Cache::flush();
        DB::enableQueryLog();
        $first = app(CategoryController::class)->index();
        $queries = DB::getQueryLog();
        $this->assertCount(20, $first['data'][0]['categories']);
        $this->assertStringContainsString('cover.jpg', $first['data'][0]['categories'][0]['image']);
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'from "media"')));

        DB::flushQueryLog();
        $this->assertSame($first, app(CategoryController::class)->index());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        DB::table('categories')->where('id', $category->id)->update(['name' => 'Updated']);
        ApiCache::clearGroup('public:catalog');
        $this->assertSame('Updated', app(CategoryController::class)->index()['data'][0]['categories'][19]['name']);
    }

    public function test_cors_cache_hit_does_not_check_database_schema(): void
    {
        Cache::flush();
        $registry = app(FrontendClientRegistry::class);
        $origins = $registry->allowedOrigins();
        DB::enableQueryLog();
        $this->assertSame($origins, $registry->allowedOrigins());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }
}
