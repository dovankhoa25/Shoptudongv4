<?php

namespace Tests\Feature;

use App\Models\ChatRealtimeSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPageSessionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        // Exercise real encrypted browser cookies and the database session
        // handler, with no operator session or external service involved.
        config([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'session.lifetime' => 120,
            'session.lottery' => [0, 100],
            'access_security.admin_approval_required' => false,
        ]);
        $this->newBrowserRequest();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_active_database_session_renders_full_admin_html_after_an_idle_period(): void
    {
        $admin = $this->admin();
        $sessionId = $this->sessionFor($admin, now()->subMinutes(30)->getTimestamp());

        $response = $this->withCookie(config('session.cookie'), $sessionId)->get('/admin');

        $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('id="app"', false)
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Page')
                ->where('auth.user.id', $admin->id)
                ->where('auth.user.balance', 12345)
                ->where('auth.is_super_admin', true)
                ->where('auth.realtime_channel', fn ($channel) => is_string($channel)
                    && str_starts_with($channel, "Chat.User.{$admin->id}.web-"))
                ->etc());
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(now()->getTimestamp(), (int) DB::table('sessions')->where('id', $sessionId)->value('last_activity'));
        $this->assertSame(1, ChatRealtimeSession::query()->where('user_id', $admin->id)->count());
    }

    public function test_expired_database_session_redirects_to_login_and_full_login_html_renders(): void
    {
        $admin = $this->admin();
        $sessionId = $this->sessionFor($admin, now()->subMinutes(121)->getTimestamp());

        $this->withCookie(config('session.cookie'), $sessionId)->get('/admin')
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, ChatRealtimeSession::query()->count());

        $this->newBrowserRequest();
        $this->get('/login')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')
                ->where('auth.user', null)->where('auth.realtime_channel', null)->etc());
    }

    public function test_deleted_database_session_with_an_existing_browser_cookie_redirects_to_login(): void
    {
        $admin = $this->admin();
        $sessionId = $this->sessionFor($admin, now()->getTimestamp());
        DB::table('sessions')->where('id', $sessionId)->delete();

        $this->withCookie(config('session.cookie'), $sessionId)->get('/admin')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, ChatRealtimeSession::query()->count());
    }

    public function test_a_following_request_does_not_reuse_a_user_from_an_expired_session(): void
    {
        $admin = $this->admin();
        $sessionId = $this->sessionFor($admin, now()->getTimestamp());
        $this->withCookie(config('session.cookie'), $sessionId)->get('/admin')->assertOk();

        DB::table('sessions')->where('id', $sessionId)->update([
            'last_activity' => now()->subMinutes(121)->getTimestamp(),
        ]);
        $this->newBrowserRequest();

        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_valid_remember_cookie_restores_a_full_admin_page_after_database_session_expiry(): void
    {
        $admin = $this->admin(['remember_token' => Str::random(60)]);
        $sessionId = $this->sessionFor($admin, now()->subMinutes(121)->getTimestamp());
        $guard = Auth::guard('web');
        $recallerName = $guard->getRecallerName();
        $recaller = $admin->id.'|'.$admin->getRememberToken().'|'.$guard->hashPasswordForCookie($admin->getAuthPassword());
        $this->newBrowserRequest();

        $this->withCookies([config('session.cookie') => $sessionId, $recallerName => $recaller])
            ->get('/admin')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Page')
                ->where('auth.user.id', $admin->id)->etc());

        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertSame(1, ChatRealtimeSession::query()->where('user_id', $admin->id)->count());
    }

    private function admin(array $attributes = []): User
    {
        $admin = User::factory()->create(['balance' => 12345, ...$attributes]);
        Role::findOrCreate('super-admin', 'web');
        $admin->assignRole('super-admin');

        return $admin;
    }

    private function sessionFor(User $admin, int $lastActivity): string
    {
        $sessionId = Str::random(40);
        $guard = Auth::guard('web');
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Admin session recovery regression',
            'payload' => base64_encode(serialize([
                '_token' => Str::random(40),
                $guard->getName() => $admin->id,
                'password_hash_web' => $guard->hashPasswordForCookie($admin->getAuthPassword()),
            ])),
            'last_activity' => $lastActivity,
        ]);
        $this->newBrowserRequest();

        return $sessionId;
    }

    private function newBrowserRequest(): void
    {
        // Laravel's HTTP test process survives between requests. Discard its
        // cached guard and session store to model a new PHP request on F5.
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
    }
}
