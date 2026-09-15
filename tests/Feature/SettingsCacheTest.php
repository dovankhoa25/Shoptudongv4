<?php
namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB};
use Tests\TestCase;

class SettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_hit_avoids_queries_and_set_invalidates_new_and_legacy_cache(): void
    {
        Cache::flush();
        Setting::set('cache-test', 'old');
        $this->assertSame('old', Setting::get('cache-test'));
        DB::enableQueryLog();
        $this->assertSame('old', Setting::get('cache-test'));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        Cache::forever('settings', ['cache-test' => 'old']);
        Setting::set('cache-test', 'new');
        $this->assertNull(Cache::get('settings'));
        $this->assertSame('new', Setting::get('cache-test'));
        $this->assertSame('fallback', Setting::get('missing', 'fallback'));
    }
}
