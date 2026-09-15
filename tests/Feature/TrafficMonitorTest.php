<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\TrafficMonitor;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrafficMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['traffic_monitor.enabled' => true, 'traffic_monitor.store' => 'array']);
        Cache::flush();
    }

    private function record(string $ip, int $status = 200): void
    {
        $request = Request::create('/api/test/123?token=private-query', 'POST', ['password' => 'private-body'], [], [], ['REMOTE_ADDR' => $ip]);
        $request->setRouteResolver(fn () => new Route('POST', 'api/test/{id}', fn () => null));
        app(TrafficMonitor::class)->record(new RequestHandled($request, response('private-response', $status)));
    }

    public function test_aggregates_endpoint_status_and_ip_without_request_secrets(): void
    {
        $this->record('1.2.3.4', 429);
        $this->record('1.2.3.4', 429);
        $this->record('5.6.7.8', 500);
        $data = app(TrafficMonitor::class)->snapshot(5);
        $this->assertSame(3, $data['totals']['count']);
        $this->assertSame(2, $data['totals']['limited']);
        $this->assertSame(1, $data['totals']['server_errors']);
        $this->assertSame('POST /api/test/{id}', $data['endpoints'][0]['key']);
        $this->assertStringNotContainsString('private', json_encode($data));
        $filtered = app(TrafficMonitor::class)->snapshot(5, '5.6.7.8', 'api', '500');
        $this->assertSame(1, $filtered['totals']['count']);
    }

    public function test_cardinality_is_bounded_and_old_ring_slots_do_not_leak_into_new_windows(): void
    {
        config(['traffic_monitor.shards' => 1, 'traffic_monitor.series_per_shard' => 2]);
        foreach (['1.2.3.4', '1.2.3.5', '1.2.3.6'] as $ip) $this->record($ip);
        $data = app(TrafficMonitor::class)->snapshot(15);
        $this->assertSame(2, $data['series']);
        $this->assertSame(1, $data['overflow']);
        $this->travel(17)->minutes();
        $this->assertSame(0, app(TrafficMonitor::class)->snapshot(15)['totals']['count']);
        $this->record('5.6.7.8');
        $this->assertSame(1, app(TrafficMonitor::class)->snapshot(15)['totals']['count']);
    }

    public function test_cloudflare_header_is_only_attributed_for_verified_peer_and_does_not_change_request_ip(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '173.245.48.2', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']);
        $monitor = app(TrafficMonitor::class);
        $this->assertSame(['ip' => '1.2.3.4', 'source' => 'cloudflare'], $monitor->client($request));
        $this->assertSame('173.245.48.2', $request->ip());
        $request->server->set('REMOTE_ADDR', '8.8.8.8');
        $this->assertSame(['ip' => '8.8.8.8', 'source' => 'peer'], $monitor->client($request));
    }

    public function test_http_errors_are_recorded_and_monitor_requires_permission(): void
    {
        \Illuminate\Support\Facades\Route::get('/api/monitor-test', fn () => abort(429));
        $this->getJson('/api/monitor-test')->assertStatus(429);
        $this->assertSame(1, app(TrafficMonitor::class)->snapshot(5)['totals']['limited']);
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/traffic')->assertForbidden();
        Role::findOrCreate('super-admin', 'web');
        $user->assignRole('super-admin');
        $this->actingAs($user)->get('/admin/traffic')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Traffic/Index'));
    }
}
