<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AdminRealtimeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/login')) ?? ''];
    }

    public function test_initial_inertia_page_contains_public_realtime_and_session_csrf_values_only(): void
    {
        config(['broadcasting.connections.ably.public_key' => 'test.public',
            'broadcasting.connections.ably.key' => 'test.public:never-expose-private-key']);
        $response = $this->withSession(['_token' => str_repeat('t', 40)])
            ->get('/login', $this->inertiaHeaders());
        $response->assertOk()->assertJsonPath('props.realtime.public_key', 'test.public')
            ->assertJsonPath('props.realtime.csrf_token', str_repeat('t', 40));
        $this->assertSame(['public_key', 'csrf_token'], array_keys($response->json('props.realtime')));
        $this->assertStringNotContainsString('never-expose-private-key', $response->getContent());
    }

    public function test_accidentally_misconfigured_public_key_does_not_expose_its_private_suffix(): void
    {
        config(['broadcasting.connections.ably.public_key' => 'test.public:private-sentinel']);
        $response = $this->get('/login', $this->inertiaHeaders())->assertOk()
            ->assertJsonPath('props.realtime.public_key', 'test.public');
        $this->assertStringNotContainsString('private-sentinel', $response->getContent());
    }

    public function test_ordinary_json_shared_props_stay_lazy_without_reading_csrf_or_creating_chat_leases(): void
    {
        config(['broadcasting.connections.ably.public_key' => 'lazy.public']);
        $session = Mockery::mock(Session::class);
        $session->shouldReceive('has')->with('errors')->once()->andReturn(false);
        $session->shouldReceive('token')->once()->andReturn('lazy-csrf');
        $request = Request::create('/admin/chat/conversations', 'GET');
        $request->headers->set('Accept', 'application/json');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => null);
        $this->mock(ChatRealtimeChannel::class)->shouldNotReceive('currentForRequest');

        DB::enableQueryLog(); DB::flushQueryLog();
        $shared = app(HandleInertiaRequests::class)->share($request);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertInstanceOf(\Closure::class, $shared['realtime']);
        $this->assertInstanceOf(\Closure::class, $shared['auth']);
        $this->assertSame([], $queries);
        $session->shouldNotHaveReceived('token');
        $this->assertSame(['public_key' => 'lazy.public', 'csrf_token' => 'lazy-csrf'], $shared['realtime']());
    }

    public function test_a_request_without_a_web_session_has_no_csrf_token(): void
    {
        config(['broadcasting.connections.ably.public_key' => 'public.only']);
        $request = Request::create('/test-json', 'GET');
        $request->setUserResolver(fn () => null);
        $shared = app(HandleInertiaRequests::class)->share($request);
        $this->assertSame(['public_key' => 'public.only', 'csrf_token' => null], $shared['realtime']());
    }

    public function test_older_cached_config_can_derive_only_the_public_prefix_from_the_server_key(): void
    {
        config(['broadcasting.connections.ably.public_key' => null,
            'broadcasting.connections.ably.key' => 'derived.public:server-private-sentinel']);
        $response = $this->get('/login', $this->inertiaHeaders())->assertOk()
            ->assertJsonPath('props.realtime.public_key', 'derived.public');
        $this->assertStringNotContainsString('server-private-sentinel', $response->getContent());
    }
}
