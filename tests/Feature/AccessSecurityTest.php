<?php

namespace Tests\Feature;

use App\Models\AccessIpBlock;
use App\Models\AdminAccessDevice;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\LoginAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Rules\DecodableImage;
use App\Services\AdminAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\Passport;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $ip = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();
        config(['access_security.admin_approval_required' => true, 'access_security.trusted_proxies' => [],
            'access_security.proxy_secret' => null]);
        $this->token = str_repeat('a', 64);
        $this->withServerVariables(['REMOTE_ADDR' => $this->ip]);
        $this->withCredentials();
        Event::fake([\App\Events\AdminEvent::class, \App\Events\UserEvent::class,
            \App\Events\ChatMessageSent::class, \App\Events\ChatInboxUpdated::class]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('dashboard.view', 'web'));

        return $user;
    }

    private function approved(User $user, ?string $token = null, ?string $ip = null): AdminAccessDevice
    {
        return AdminAccessDevice::create(['user_id' => $user->id,
            'device_hash' => hash('sha256', $token ?? $this->token), 'ip_address' => $ip ?? $this->ip,
            'status' => 'approved', 'approved_at' => now(), 'expires_at' => now()->addDays(30)]);
    }

    public function test_correct_password_from_unknown_device_waits_without_logging_in_or_banning_account(): void
    {
        $admin = $this->admin();
        $response = $this->postJson('/login', ['username' => $admin->username, 'password' => 'password']);
        $response->assertUnprocessable()->assertJsonValidationErrors('username')
            ->assertCookie(config('access_security.device_cookie'));
        $this->assertGuest();
        $this->assertDatabaseHas('admin_access_devices', ['user_id' => $admin->id, 'status' => 'pending', 'ip_address' => $this->ip]);
        $this->assertDatabaseHas('login_attempts', ['user_id' => $admin->id, 'is_success' => false, 'failure_reason' => 'admin_access_pending']);
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_admin_can_disable_policy_without_deleting_existing_approvals(): void
    {
        $admin = $this->admin();
        $device = $this->approved($admin);
        $this->actingAs($admin)->withCookie(config('access_security.device_cookie'), $this->token);
        $this->getJson('/admin/access-security')->assertOk()->assertJsonPath('admin_approval_required', true);
        $this->postJson('/admin/access-security/policy', ['enabled' => false])->assertOk()
            ->assertJsonPath('admin_approval_required', false);
        $this->getJson('/admin/access-security')->assertOk()->assertJsonPath('admin_approval_required', false);
        $this->assertSame('approved', $device->fresh()->status);
        $this->assertDatabaseHas('settings', ['key' => AdminAccessService::APPROVAL_SETTING, 'value' => '0']);
        $event = \App\Models\UserSecurityLog::where('event', 'admin_access_policy_changed')->sole();
        $this->assertSame($admin->id, $event->user_id);
        $this->assertFalse($event->meta['enabled']);
        $this->assertTrue($event->meta['previous_enabled']);
    }

    public function test_enabling_from_an_unknown_browser_keeps_operator_access_but_blocks_other_browsers(): void
    {
        Setting::set(AdminAccessService::APPROVAL_SETTING, '0');
        $admin = $this->admin();
        $this->actingAs($admin);
        $name = config('access_security.device_cookie');
        $response = $this->postJson('/admin/access-security/policy', ['enabled' => true]);
        $response->assertOk()->assertJsonPath('admin_approval_required', true)->assertCookie($name);
        $token = $response->getCookie($name)->getValue();
        $this->assertDatabaseHas('admin_access_devices', ['user_id' => $admin->id,
            'device_hash' => hash('sha256', $token), 'ip_address' => $this->ip, 'status' => 'approved']);
        $this->withCookie($name, $token)->getJson('/admin/access-security')->assertOk();
        $this->withCookie($name, str_repeat('b', 64))->getJson('/admin/access-security')->assertForbidden();
        $this->assertGuest();
    }

    public function test_disabled_policy_allows_verified_admin_login_but_still_checks_credentials_and_account_lock(): void
    {
        Setting::set(AdminAccessService::APPROVAL_SETTING, '0');
        $admin = $this->admin();
        $this->postJson('/login', ['username' => $admin->username, 'password' => 'wrong'])->assertUnprocessable();
        $this->assertGuest();
        $admin->update(['status' => 'banned']);
        $this->postJson('/login', ['username' => $admin->username, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
        $admin->update(['status' => 'active']);
        $this->post('/login', ['username' => $admin->username, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseCount('admin_access_devices', 0);
    }

    public function test_disabled_approval_does_not_disable_ip_blocks(): void
    {
        Setting::set(AdminAccessService::APPROVAL_SETTING, '0');
        $admin = $this->admin();
        AccessIpBlock::create(['network' => '203.0.113.0/24', 'reason' => 'test']);
        $this->postJson('/login', ['username' => $admin->username, 'password' => 'password'])->assertForbidden();
        $this->assertGuest();
    }

    public function test_policy_mutation_requires_security_permission_and_valid_boolean(): void
    {
        Setting::set(AdminAccessService::APPROVAL_SETTING, '0');
        $this->actingAs(User::factory()->create());
        $this->postJson('/admin/access-security/policy', ['enabled' => true])->assertForbidden();
        $this->actingAs($this->admin());
        $this->postJson('/admin/access-security/policy', ['enabled' => 'not-a-boolean'])->assertUnprocessable();
        $this->postJson('/admin/access-security/policy', [])->assertUnprocessable();
        $this->assertFalse(app(AdminAccessService::class)->approvalRequired());
    }

    public function test_policy_uses_shared_settings_cache_and_updates_existing_service_instances(): void
    {
        config(['access_security.admin_approval_required' => false]);
        $service = app(AdminAccessService::class);
        $this->assertFalse($service->approvalRequired());
        $service->setApprovalRequired(true);
        $this->assertTrue($service->approvalRequired());
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->assertTrue($service->approvalRequired());
        $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $service->setApprovalRequired(false);
        $this->assertFalse($service->approvalRequired());
        Setting::set(AdminAccessService::APPROVAL_SETTING, 'invalid');
        $this->assertTrue($service->approvalRequired());
        Setting::set(AdminAccessService::APPROVAL_SETTING, '');
        $this->assertTrue($service->approvalRequired());
    }

    public function test_console_can_recover_policy_and_report_current_state(): void
    {
        $this->artisan('security:admin-access', ['action' => 'disable'])
            ->expectsOutput('Admin login approval: DISABLED')->assertSuccessful();
        $this->assertFalse(app(AdminAccessService::class)->approvalRequired());
        $this->artisan('security:admin-access', ['action' => 'enable'])
            ->expectsOutput('Admin login approval: ENABLED')->assertSuccessful();
        $this->artisan('security:admin-access', ['action' => 'status'])
            ->expectsOutput('Admin login approval: ENABLED')->assertSuccessful();
        $this->assertTrue(app(AdminAccessService::class)->approvalRequired());
        $this->assertDatabaseCount('admin_access_devices', 0);
    }

    public function test_wrong_password_does_not_create_an_approval_request(): void
    {
        $admin = $this->admin();
        $this->postJson('/login', ['username' => $admin->username, 'password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseCount('admin_access_devices', 0);
        $this->assertGuest();
    }

    public function test_approved_cookie_and_ip_can_login(): void
    {
        $admin = $this->admin();
        $this->approved($admin);
        $this->withCookie(config('access_security.device_cookie'), $this->token)
            ->post('/login', ['username' => $admin->username, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_changed_ip_even_with_same_cookie_requires_another_approval(): void
    {
        $admin = $this->admin();
        $this->approved($admin, ip: '203.0.113.11');
        $this->withCookie(config('access_security.device_cookie'), $this->token)
            ->postJson('/login', ['username' => $admin->username, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
        $this->assertDatabaseHas('admin_access_devices', ['user_id' => $admin->id, 'ip_address' => $this->ip, 'status' => 'pending']);
    }

    public function test_copied_user_agent_cannot_replace_the_device_cookie(): void
    {
        $admin = $this->admin();
        $this->approved($admin)->update(['user_agent' => 'known-browser']);
        $this->withHeader('User-Agent', 'known-browser')
            ->postJson('/login', ['username' => $admin->username, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_revoked_device_blocks_an_existing_admin_session_on_next_request(): void
    {
        $admin = $this->admin();
        $device = $this->approved($admin);
        $this->actingAs($admin)->withCookie(config('access_security.device_cookie'), $this->token)
            ->getJson('/admin/access-security')->assertOk();
        app(AdminAccessService::class)->revoke($device, $admin);
        $this->getJson('/admin/access-security')->assertForbidden();
        $this->assertGuest();
    }

    public function test_expired_device_returns_to_pending(): void
    {
        $admin = $this->admin();
        $device = $this->approved($admin);
        $device->update(['expires_at' => now()->subMinute()]);
        $this->withCookie(config('access_security.device_cookie'), $this->token)
            ->postJson('/login', ['username' => $admin->username, 'password' => 'password'])->assertUnprocessable();
        $this->assertSame('pending', $device->fresh()->status);
    }

    public function test_ordinary_customer_web_login_does_not_require_admin_approval(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('admin_access_devices', 0);
    }

    public static function approvalModes(): array
    {
        return ['enabled' => [true], 'disabled' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('approvalModes')]
    public function test_google_login_follows_the_current_approval_policy(bool $enabled): void
    {
        Setting::set(AdminAccessService::APPROVAL_SETTING, $enabled ? '1' : '0');
        $admin = $this->admin();
        $admin->authProviders()->create(['provider' => 'google', 'provider_id' => 'known-google', 'is_enabled' => true]);
        $social = (new SocialUser)->setRaw(['sub' => 'known-google', 'email' => $admin->email])
            ->map(['id' => 'known-google', 'email' => $admin->email, 'name' => 'Known Admin', 'avatar' => null]);
        $provider = \Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
        if (! $enabled) {
            $this->getJson('/auth/google/callback')->assertRedirect();
            $this->assertAuthenticatedAs($admin);
            $this->assertDatabaseCount('admin_access_devices', 0);

            return;
        }
        $this->getJson('/auth/google/callback')->assertUnprocessable();
        $this->assertGuest();
        $this->assertDatabaseHas('login_attempts', ['user_id' => $admin->id, 'provider' => 'google', 'failure_reason' => 'admin_access_pending']);
    }

    public function test_console_can_approve_first_device(): void
    {
        $admin = $this->admin();
        $device = $this->approved($admin);
        $device->update(['status' => 'pending']);
        $this->artisan('security:admin-access', ['action' => 'approve', 'id' => $device->id])->assertSuccessful();
        $this->assertSame('approved', $device->fresh()->status);
        $this->assertDatabaseHas('user_security_logs', ['user_id' => $admin->id, 'event' => 'admin_access_approved']);
    }

    public function test_new_device_cookie_can_login_after_console_approval(): void
    {
        $admin = $this->admin();
        $name = config('access_security.device_cookie');
        $response = $this->postJson('/login', ['username' => $admin->username, 'password' => 'password']);
        $response->assertUnprocessable();
        $token = $response->getCookie($name)->getValue();
        $device = AdminAccessDevice::sole();
        $this->assertSame(hash('sha256', $token), $device->device_hash);
        $this->artisan('security:admin-access', ['action' => 'approve', 'id' => $device->id])->assertSuccessful();
        $this->withCookie($name, $token)->post('/login', [
            'username' => $admin->username, 'password' => 'password',
        ])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_approved_admin_can_approve_another_device_but_cannot_approve_a_banned_user(): void
    {
        $admin = $this->admin();
        $this->approved($admin);
        $target = $this->admin();
        $device = $this->approved($target, str_repeat('b', 64));
        $device->update(['status' => 'pending']);
        $this->actingAs($admin)->withCookie(config('access_security.device_cookie'), $this->token);
        $this->postJson('/admin/access-security/devices/'.$device->id.'/approve')->assertOk();
        $this->assertSame($admin->id, $device->fresh()->approved_by);
        $target->update(['status' => 'banned']);
        $device->refresh()->update(['status' => 'pending']);
        $this->postJson('/admin/access-security/devices/'.$device->id.'/approve')->assertUnprocessable();
        $this->assertSame('pending', $device->fresh()->status);
    }

    public function test_admin_can_block_cidr_and_unblock_with_audit_without_self_locking(): void
    {
        $admin = $this->admin();
        $this->approved($admin);
        $this->actingAs($admin)->withCookie(config('access_security.device_cookie'), $this->token);
        $response = $this->postJson('/admin/access-security/blocks', ['network' => '198.51.100.23/24', 'reason' => 'Repeated probes', 'hours' => 2]);
        $response->assertCreated()->assertJsonPath('block.network', '198.51.100.0/24');
        $this->postJson('/admin/access-security/blocks', ['network' => '203.0.113.0/24', 'reason' => 'self'])->assertUnprocessable();
        $this->postJson('/admin/access-security/blocks', ['network' => '0.0.0.0/0', 'reason' => 'all'])->assertUnprocessable();
        $this->deleteJson('/admin/access-security/blocks/'.$response->json('block.id'))->assertOk();
        $this->assertDatabaseHas('user_security_logs', ['user_id' => $admin->id, 'event' => 'ip_range_unblocked']);
    }

    public function test_customer_cannot_read_other_users_history_or_manage_security(): void
    {
        $customer = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($customer)->getJson('/admin/users/'.$target->id.'/security')->assertForbidden();
        $this->postJson('/admin/access-security/blocks', ['network' => '198.51.100.1', 'reason' => 'test'])->assertForbidden();
        $this->getJson('/admin/access-security')->assertForbidden();
        $this->patchJson('/admin/nro-shop/sale-policy', [])->assertForbidden();
        $this->postJson('/admin/nro-shop/orders/999/refund', [])->assertForbidden();
    }

    public function test_cache_invalidation_failure_reports_503_but_keeps_the_committed_ban_audit(): void
    {
        $admin = $this->admin();
        $this->approved($admin);
        $this->actingAs($admin)->withCookie(config('access_security.device_cookie'), $this->token);
        \Illuminate\Support\Facades\Cache::partialMock()->shouldReceive('store')
            ->andThrow(new \RuntimeException('cache unavailable'));
        $this->postJson('/admin/access-security/blocks', ['network' => '198.51.100.0/24', 'reason' => 'test'])
            ->assertStatus(503);
        $this->assertDatabaseHas('access_ip_blocks', ['network' => '198.51.100.0/24']);
        $this->assertDatabaseHas('user_security_logs', ['user_id' => $admin->id, 'event' => 'ip_range_blocked']);
    }

    public static function realtimeDenials(): array
    {
        return ['revoked' => ['revoked'], 'expired' => ['expired'], 'ip_blocked' => ['ip_blocked'],
            'policy_enabled' => ['policy_enabled']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realtimeDenials')]
    public function test_admin_live_view_works_after_approval_and_stops_publishing_after_access_denial(string $mode): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        Event::fake([\App\Events\AdminViewPatched::class, \App\Events\UserEvent::class, \App\Events\AdminEvent::class]);
        $admin = $this->admin();
        $device = $this->approved($admin);
        if ($mode === 'policy_enabled') {
            Setting::set(AdminAccessService::APPROVAL_SETTING, '0');
        }
        $this->actingAs($admin)->withSession([
            \Illuminate\Support\Facades\Auth::guard('web')->getName() => $admin->id,
        ])->withCookie(config('session.cookie'), app('session')->getId())
            ->withCookie(config('access_security.device_cookie'), $this->token);
        $id = $this->postJson('/admin/live-views', ['url' => '/admin/users'])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$id.'/sync')->assertOk();
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('chat_realtime_sessions', [
            'user_id' => $admin->id, 'admin_access_device_id' => $mode === 'policy_enabled' ? null : $device->id,
        ]);
        if ($mode === 'policy_enabled') {
            Setting::set(AdminAccessService::APPROVAL_SETTING, '1');
        } elseif ($mode === 'ip_blocked') {
            AccessIpBlock::create(['network' => '203.0.113.0/24', 'reason' => 'Blocked by another admin']);
        } else {
            $device->update($mode === 'revoked' ? ['status' => 'revoked'] : ['expires_at' => now()->subMinute()]);
        }
        // No browser request is needed: the publisher must stop sending private data immediately.
        $updates = app(\App\Services\AdminLive\Updates::class);
        $updates->changed('user');
        $updates->flush();
        Event::assertDispatched(\App\Events\AdminViewPatched::class, fn ($event) => ($event->frame['revoked'] ?? false) === true);
        $this->assertDatabaseMissing('admin_live_views', ['id' => $id]);
    }

    public function test_history_excludes_sensitive_metadata_and_other_users(): void
    {
        $admin = $this->admin();
        $this->approved($admin);
        $user = User::factory()->create();
        LoginAttempt::create(['user_id' => $user->id, 'username' => $user->username, 'ip_address' => '198.51.100.25',
            'meta' => ['channel' => 'api', 'secret' => 'never-return-me'], 'is_success' => true]);
        LoginAttempt::create(['user_id' => $admin->id, 'username' => 'not-in-target-history', 'is_success' => true]);
        $r = $this->actingAs($admin)->withCookie(config('access_security.device_cookie'), $this->token)
            ->getJson('/admin/users/'.$user->id.'/security');
        $r->assertOk()->assertJsonPath('attempts.total', 1)->assertJsonPath('attempts.data.0.meta.channel', 'api');
        $this->assertStringNotContainsString('never-return-me', $r->getContent());
        $this->assertStringNotContainsString('not-in-target-history', $r->getContent());
    }

    public function test_banned_network_blocks_web_and_existing_api_session_but_not_worker_auth(): void
    {
        AccessIpBlock::create(['network' => '203.0.113.0/24', 'reason' => 'test']);
        $this->get('/login')->assertForbidden();
        $user = User::factory()->create();
        Passport::actingAs($user, ['profile:read']);
        $this->getJson('/api/profile')->assertForbidden();
        $this->getJson('/api/app/carot/recharges/pending')->assertUnauthorized();
    }

    public function test_expired_and_revoked_ip_blocks_do_not_block(): void
    {
        AccessIpBlock::create(['network' => '203.0.113.0/24', 'reason' => 'expired', 'expires_at' => now()->subMinute()]);
        AccessIpBlock::create(['network' => '203.0.113.0/24', 'reason' => 'revoked', 'revoked_at' => now()]);
        $this->get('/login')->assertOk();
    }

    public function test_ipv6_network_is_normalized_and_enforced(): void
    {
        $this->assertSame('2001:db8:abcd::/48', \App\Support\IpNetwork::normalize('2001:db8:abcd:12::3/48'));
        AccessIpBlock::create(['network' => '2001:db8:abcd::/48', 'reason' => 'test']);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:abcd:99::1'])->get('/login')->assertForbidden();
    }

    public function test_arbitrary_forwarded_header_cannot_evade_ip_block(): void
    {
        AccessIpBlock::create(['network' => '203.0.113.0/24', 'reason' => 'test']);
        $this->withHeader('X-Forwarded-For', '198.51.100.99')->get('/login')->assertForbidden();
    }

    public function test_only_configured_proxy_can_supply_client_ip(): void
    {
        config(['access_security.trusted_proxies' => [$this->ip]]);
        AccessIpBlock::create(['network' => '198.51.100.0/24', 'reason' => 'test']);
        $this->withHeader('X-Forwarded-For', '198.51.100.99')->get('/login')->assertForbidden();
    }

    public function test_signed_forwarding_binds_client_ip_to_path_body_and_time(): void
    {
        $secret = str_repeat('k', 40);
        config(['access_security.proxy_secret' => $secret]);
        Route::post('/security-test-ip', fn (Request $r) => response()->json(['ip' => $r->ip()]))->middleware('api');
        $body = '{"a":1}';
        $time = (string) time();
        $ip = '198.51.100.42';
        $agent = 'Test browser';
        $signature = hash_hmac('sha256', implode("\n", ['POST', '/security-test-ip', $time, $ip, $agent, hash('sha256', $body)]), $secret);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => $this->ip, 'HTTP_X_SHOP_CLIENT_IP' => $ip, 'HTTP_X_SHOP_CLIENT_AGENT' => $agent,
            'HTTP_X_SHOP_CLIENT_TIME' => $time, 'HTTP_X_SHOP_CLIENT_SIGNATURE' => $signature];
        $this->call('POST', '/security-test-ip', [], [], [], $headers, $body)->assertJsonPath('ip', $ip);
        $this->call('POST', '/security-test-ip', [], [], [], $headers, '{"a":2}')->assertJsonPath('ip', $this->ip);
        $headers['HTTP_X_SHOP_CLIENT_TIME'] = (string) (time() - 120);
        $this->call('POST', '/security-test-ip', [], [], [], $headers, $body)->assertJsonPath('ip', $this->ip);
    }

    public function test_sso_management_api_also_requires_approved_admin_device(): void
    {
        $user = User::factory()->create();
        config(['sso.admin_user_ids' => [(string) $user->id]]);
        Passport::actingAs($user, ['oauth-clients:manage']);
        $this->getJson('/api/admin/oauth-clients')->assertForbidden();
        Setting::set(AdminAccessService::APPROVAL_SETTING, '0');
        $this->getJson('/api/admin/oauth-clients')->assertOk();
        Setting::set(AdminAccessService::APPROVAL_SETTING, '1');
        $this->getJson('/api/admin/oauth-clients')->assertForbidden();
        $this->approved($user);
        $this->withCookie(config('access_security.device_cookie'), $this->token)->getJson('/api/admin/oauth-clients')->assertOk();
    }

    public function test_header_only_png_is_rejected_before_creating_chat_message(): void
    {
        $user = User::factory()->create();
        $chat = ChatConversation::create(['customer_id' => $user->id, 'category' => 'general']);
        $file = UploadedFile::fake()->createWithContent('probe.png', hex2bin('89504e470d0a1a0a0000000d4948445200000001000000010802000000907753de'));
        $this->actingAs($user)->postJson('/chat/conversations/'.$chat->id.'/messages', ['images' => [$file]])
            ->assertUnprocessable()->assertJsonValidationErrors('images.0');
        $this->assertSame(0, ChatMessage::where('conversation_id', $chat->id)->count());
    }

    public function test_real_image_still_passes_integrity_validation(): void
    {
        $this->assertFalse(Validator::make(['image' => UploadedFile::fake()->image('photo.png', 200, 200)],
            ['image' => [new DecodableImage]])->fails());
    }

    public function test_web_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/login', ['username' => 'nonexistent', 'password' => 'bad'])->assertUnprocessable();
        }
        $this->postJson('/login', ['username' => 'nonexistent', 'password' => 'bad'])->assertTooManyRequests();
    }
}
