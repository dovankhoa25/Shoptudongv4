<?php

namespace Tests\Feature;

use App\Http\Middleware\StartTrafficTiming;
use App\Models\User;
use App\Services\TrafficMonitor;
use App\Services\TrafficMonitorState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrafficMonitorSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['traffic_monitor.enabled' => false, 'traffic_monitor.store' => 'array']);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('traffic.manage', 'web'));
        return $user;
    }

    public function test_saved_switch_survives_service_recreation_and_overrides_environment_default(): void
    {
        $state = app(TrafficMonitorState::class);
        $this->assertFalse($state->enabled());
        config(['traffic_monitor.enabled' => true]);
        $this->assertTrue($state->enabled());

        $state->setEnabled(false);
        $this->assertFalse((new TrafficMonitorState(new Filesystem))->enabled());
        config(['traffic_monitor.enabled' => false]);
        $state->setEnabled(true);
        $this->assertTrue($state->enabled());
        $this->assertTrue((new TrafficMonitorState(new Filesystem))->enabled());

        file_put_contents(config('traffic_monitor.state_path'), 'invalid');
        $this->assertFalse($state->enabled());
    }

    public function test_disabled_requests_and_snapshot_never_access_the_monitor_cache(): void
    {
        Cache::spy();
        $request = Request::create('/api/test', 'GET');
        $response = app(StartTrafficTiming::class)->handle($request, fn () => response('ok'));
        $this->assertFalse($request->attributes->get('_traffic_enabled'));
        $this->assertFalse($request->attributes->has('_traffic_started'));

        $monitor = app(TrafficMonitor::class);
        $monitor->record(new RequestHandled($request, $response));
        // Direct event callers also respect the switch without the timing middleware.
        $monitor->record(new RequestHandled(Request::create('/api/other'), $response));
        $snapshot = $monitor->snapshot(15);
        $this->assertFalse($snapshot['enabled']);
        $this->assertSame(0, $snapshot['totals']['count']);
        $this->assertSame([], $snapshot['rows']);
        Cache::shouldNotHaveReceived('store');
    }

    public function test_manager_can_toggle_recording_for_subsequent_http_requests_without_flushing_cache(): void
    {
        $this->actingAs($this->manager());
        Route::get('/api/traffic-switch-test', fn (Request $request) => response()->json([
            'timed' => $request->attributes->has('_traffic_started'),
        ]));
        Cache::put('business-cache-test', 'keep', 600);
        $monitor = app(TrafficMonitor::class);

        $this->getJson('/api/traffic-switch-test')->assertOk()->assertJsonPath('timed', false);
        $this->from('/admin/traffic')->patch('/admin/traffic', ['enabled' => true])
            ->assertRedirect('/admin/traffic')->assertSessionHasNoErrors();
        $this->get('/admin/traffic')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Traffic/Index')->where('traffic.enabled', true)->where('canManage', true));
        $this->getJson('/api/traffic-switch-test')->assertOk()->assertJsonPath('timed', true);
        $this->assertSame(1, $monitor->snapshot(5)['totals']['count']);

        $this->from('/admin/traffic')->patch('/admin/traffic', ['enabled' => false])
            ->assertRedirect('/admin/traffic')->assertSessionHasNoErrors();
        $this->getJson('/api/traffic-switch-test')->assertOk()->assertJsonPath('timed', false);
        $this->get('/admin/traffic')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('traffic.enabled', false)->where('traffic.rows', []));
        $this->assertSame('keep', Cache::get('business-cache-test'));

        app(TrafficMonitorState::class)->setEnabled(true);
        // The admin page/toggle and requests made while disabled added no counts.
        $this->assertSame(1, $monitor->snapshot(5)['totals']['count']);
    }

    public function test_guest_and_view_only_users_cannot_change_the_switch(): void
    {
        $this->patchJson('/admin/traffic', ['enabled' => true])->assertUnauthorized();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate('traffic.view', 'web'));
        $this->actingAs($viewer)->get('/admin/traffic')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
        $this->patchJson('/admin/traffic', ['enabled' => true])->assertForbidden();
        $this->assertFileDoesNotExist(config('traffic_monitor.state_path'));
    }

    public function test_invalid_toggle_value_does_not_change_saved_state(): void
    {
        $this->actingAs($this->manager())->patchJson('/admin/traffic', ['enabled' => 'anything'])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->assertFalse(app(TrafficMonitorState::class)->enabled());
        $this->assertFileDoesNotExist(config('traffic_monitor.state_path'));
    }

    public function test_write_failure_keeps_previous_state_and_reports_a_form_error(): void
    {
        $this->actingAs($this->manager());
        app(TrafficMonitorState::class)->setEnabled(false);
        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('ensureDirectoryExists')->once();
        $files->shouldReceive('replace')->once()->andThrow(new RuntimeException('Read-only storage'));
        $this->app->instance(TrafficMonitorState::class, new TrafficMonitorState($files));

        $this->from('/admin/traffic')->patch('/admin/traffic', ['enabled' => true])
            ->assertRedirect('/admin/traffic')->assertSessionHasErrors('enabled');
        $this->assertFalse(app(TrafficMonitorState::class)->enabled());
    }

    public function test_super_admin_can_manage_the_switch(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        $this->actingAs($user)->from('/admin/traffic')->patch('/admin/traffic', ['enabled' => true])
            ->assertRedirect('/admin/traffic')->assertSessionHasNoErrors();
        $this->assertTrue(app(TrafficMonitorState::class)->enabled());
    }

    public function test_permission_migration_grants_existing_admin_roles_only(): void
    {
        $admin = Role::findOrCreate('admin', 'web');
        $superAdmin = Role::findOrCreate('super-admin', 'web');
        $viewer = Role::findOrCreate('ctv', 'web');
        $migration = require database_path('migrations/2026_09_16_000001_add_traffic_manage_permission.php');
        $migration->up();
        $migration->up();
        $this->assertTrue($admin->fresh()->hasPermissionTo('traffic.manage'));
        $this->assertTrue($superAdmin->fresh()->hasPermissionTo('traffic.manage'));
        $this->assertFalse($viewer->fresh()->hasPermissionTo('traffic.manage'));
    }
}
