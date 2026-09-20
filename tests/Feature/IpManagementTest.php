<?php

namespace Tests\Feature;

use App\Models\AccessIpBlock;
use App\Models\AdminAccessDevice;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IpManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Event::fake([\App\Events\AdminEvent::class, \App\Events\UserEvent::class]);
        config(['access_security.admin_approval_required' => false]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));
        $this->actingAs($admin);
    }

    private function login(User $user, string $ip, bool $success = true, int $daysAgo = 0): void
    {
        LoginAttempt::forceCreate(['user_id' => $user->id, 'username' => $user->username,
            'ip_address' => $ip, 'is_success' => $success, 'created_at' => now()->subDays($daysAgo),
            'meta' => ['channel' => 'web', 'ip_source' => 'peer', 'session_id' => 'must-not-leak']]);
    }

    public function test_sidebar_destinations_render_and_security_permission_is_required(): void
    {
        foreach (['overview', 'devices', 'blocks', 'logins'] as $section) {
            $this->get('/admin/ip-management/'.$section)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/IpManagement/Index')->where('section', $section));
        }
        $this->get('/admin/ip-management')->assertRedirect('/admin/ip-management/overview');
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('users.view', 'web'));
        $this->actingAs($user);
        foreach (['overview', 'devices', 'blocks', 'logins', 'ip-detail?ip=198.51.100.5'] as $path) {
            $this->getJson('/admin/ip-management/'.$path)->assertForbidden();
        }
        $user->givePermissionTo(Permission::findOrCreate('access-security.manage', 'web'));
        $this->get('/admin/ip-management/overview')->assertOk();
    }

    public function test_default_overview_counts_distinct_successful_accounts_only_within_30_days(): void
    {
        [$one, $two, $three] = User::factory()->count(3)->create()->all();
        $this->login($one, '198.51.100.5');
        $this->login($one, '198.51.100.5');
        $this->login($two, '198.51.100.5');
        $this->login($three, '198.51.100.5', false);
        $this->login($one, '198.51.100.6');
        $this->login($two, '198.51.100.6', false);
        $this->login($three, '198.51.100.6', true, 40);
        $this->login($two, '198.51.100.7', true, 40);
        $this->login($three, '198.51.100.7', true, 40);
        AccessIpBlock::create(['network' => '198.51.100.0/24', 'reason' => 'Test CIDR']);
        AccessIpBlock::create(['network' => '198.51.100.5', 'reason' => 'Expired', 'expires_at' => now()->subSecond()]);
        $this->get('/admin/ip-management/overview')->assertInertia(fn (Assert $page) => $page
            ->where('filters.days', 30)->where('filters.status', 'shared')->where('listing.total', 1)
            ->where('listing.data.0.ip_address', '198.51.100.5')->where('listing.data.0.users_count', 2)
            ->where('listing.data.0.successful_logins', 3)->has('listing.data.0.users', 2)
            ->has('listing.data.0.blocks', 1)->where('listing.data.0.blocks.0.network', '198.51.100.0/24')
            ->where('summary.active_blocks', 1));
        $this->get('/admin/ip-management/overview?status=all')->assertInertia(fn (Assert $page) => $page->where('listing.total', 2));
        $this->get('/admin/ip-management/overview?days=90')->assertInertia(fn (Assert $page) => $page->where('listing.total', 3));
    }

    public function test_user_search_keeps_other_accounts_sharing_the_matching_ip_and_limits_previews(): void
    {
        $users = User::factory()->count(5)->create();
        foreach ($users as $user) {
            $this->login($user, '2001:db8:1234::1');
        }
        $this->get('/admin/ip-management/overview?search='.urlencode('#'.$users[0]->id))
            ->assertInertia(fn (Assert $page) => $page->where('listing.total', 1)
                ->where('listing.data.0.users_count', 5)->has('listing.data.0.users', 3));
        $this->get('/admin/ip-management/overview?search='.urlencode($users[0]->username))
            ->assertInertia(fn (Assert $page) => $page->where('listing.data.0.users_count', 5));
    }

    public function test_detail_paginates_users_includes_recent_failures_and_removes_sensitive_metadata(): void
    {
        $users = User::factory()->count(22)->create();
        foreach ($users as $user) {
            $this->login($user, '198.51.100.5');
        }
        $this->login($users[0], '198.51.100.5', false);
        $response = $this->getJson('/admin/ip-management/ip-detail?ip=198.51.100.5');
        $response->assertOk()->assertJsonPath('users.total', 22)->assertJsonCount(20, 'users.data')
            ->assertJsonCount(10, 'recent')->assertJsonPath('recent.0.is_success', false)
            ->assertJsonPath('recent.0.meta.ip_source', 'peer')->assertJsonMissingPath('recent.0.meta.session_id');
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $this->getJson('/admin/ip-management/ip-detail?ip=198.51.100.5&page=2')->assertOk()->assertJsonCount(2, 'users.data');
        $this->getJson('/admin/ip-management/ip-detail?ip=invalid')->assertUnprocessable();
        $this->getJson('/admin/ip-management/overview?days=3650')->assertUnprocessable();
        $this->getJson('/admin/ip-management/devices?status=success')->assertUnprocessable();
    }

    public function test_device_filters_distinguish_expired_approvals_and_hide_cookie_hash(): void
    {
        $user = User::factory()->create();
        foreach (['pending', 'approved', 'expired', 'revoked'] as $index => $state) {
            AdminAccessDevice::create(['user_id' => $user->id, 'device_hash' => str_repeat((string) $index, 64),
                'ip_address' => '198.51.100.5', 'status' => $state === 'expired' ? 'approved' : $state,
                'expires_at' => $state === 'expired' ? now()->subDay() : now()->addDay()]);
        }
        foreach (['pending', 'approved', 'expired', 'revoked'] as $state) {
            $this->get('/admin/ip-management/devices?status='.$state)->assertInertia(fn (Assert $page) => $page
                ->where('listing.total', 1)->where('listing.data.0.display_status', $state)->missing('listing.data.0.device_hash'));
        }
    }

    public function test_block_filters_keep_revoked_and_expired_history_separate(): void
    {
        AccessIpBlock::create(['network' => '198.51.100.1', 'reason' => 'active']);
        AccessIpBlock::create(['network' => '198.51.100.2', 'reason' => 'expired', 'expires_at' => now()->subDay()]);
        AccessIpBlock::create(['network' => '198.51.100.3', 'reason' => 'revoked', 'expires_at' => now()->subDay(), 'revoked_at' => now()]);
        foreach (['active', 'expired', 'revoked'] as $state) {
            $this->get('/admin/ip-management/blocks?status='.$state)->assertInertia(fn (Assert $page) => $page
                ->where('listing.total', 1)->where('listing.data.0.display_status', $state));
        }
    }

    public function test_login_filters_show_only_selected_ip_and_result_without_credentials(): void
    {
        $user = User::factory()->create();
        $this->login($user, '198.51.100.5');
        $this->login($user, '198.51.100.5', false);
        $this->login($user, '198.51.100.6', false);
        $this->get('/admin/ip-management/logins?ip=198.51.100.5&status=failed')->assertInertia(fn (Assert $page) => $page
            ->where('listing.total', 1)->where('listing.data.0.is_success', false)
            ->where('listing.data.0.meta.ip_source', 'peer')->missing('listing.data.0.meta.session_id')
            ->missing('listing.data.0.user.password')->missing('listing.data.0.user.email'));
    }
}
