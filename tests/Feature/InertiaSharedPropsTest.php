<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_json_response_does_not_build_inertia_auth_props(): void
    {
        Route::middleware('web')->get('/_test/shared-json', fn () => response()->json(['ok' => true]));
        $this->actingAs(User::factory()->create());
        DB::enableQueryLog();

        $this->getJson('/_test/shared-json')->assertOk()->assertExactJson(['ok' => true]);

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();
        foreach (['user_balance_realtime', 'media', 'chat_realtime_sessions', 'model_has_roles'] as $table) {
            $this->assertStringNotContainsString($table, $queries);
        }
    }

    public function test_rendered_inertia_page_still_receives_auth_and_current_balance(): void
    {
        Route::middleware('web')->get('/_test/shared-page', fn () => Inertia::render('Admin/Page', ['probe' => 1]));
        $user = User::factory()->create(['balance' => 12345]);

        $this->actingAs($user)->get('/_test/shared-page')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Page')
                ->where('auth.user.id', $user->id)
                ->where('auth.user.balance', 12345)
                ->where('auth.roles', [])
                ->where('auth.permissions', [])
                ->has('auth.realtime_channel')->etc());
    }

    public function test_partial_reload_excluding_auth_does_not_query_balance_or_media(): void
    {
        Route::middleware('web')->get('/_test/shared-partial', fn () => Inertia::render('Admin/Page', ['probe' => 2]));
        $this->actingAs(User::factory()->create());
        $version = $this->get('/_test/shared-partial')->assertOk()->viewData('page')['version'];
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get('/_test/shared-partial', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Admin/Page',
            'X-Inertia-Partial-Data' => 'probe',
        ])->assertOk()->assertJsonPath('props.probe', 2)->assertJsonMissingPath('props.auth');

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();
        foreach (['user_balance_realtime', 'media', 'chat_realtime_sessions'] as $table) {
            $this->assertStringNotContainsString($table, $queries);
        }
    }
}
