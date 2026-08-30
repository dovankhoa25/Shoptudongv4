<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Http\Middleware\RequirePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (AppPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_permission_middleware_denies_user_without_permission(): void
    {
        $user = User::factory()->create();

        try {
            $this->runMiddlewareAs($user, AppPermission::UsersView->value);
            $this->fail('Middleware should deny a user without permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_permission_middleware_allows_granted_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AppPermission::UsersView->value);

        $response = $this->runMiddlewareAs($user, AppPermission::UsersView->value);

        $this->assertSame('allowed', $response->getContent());
    }

    public function test_super_admin_bypasses_route_permission_only(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('super-admin', 'web');
        $user->assignRole('super-admin');

        $response = $this->runMiddlewareAs($user, AppPermission::UsersAdjustBalance->value);

        $this->assertSame('allowed', $response->getContent());
    }

    public function test_admin_cannot_update_or_lock_self_or_another_admin(): void
    {
        $adminRole = Role::findOrCreate('admin', 'web');
        $actor = User::factory()->create();
        $peer = User::factory()->create();
        $actor->assignRole($adminRole);
        $peer->assignRole($adminRole);
        $actor->givePermissionTo([
            AppPermission::UsersUpdate->value,
            AppPermission::UsersLock->value,
        ]);

        $this->assertFalse($actor->can('update', $actor));
        $this->assertFalse($actor->can('lock', $actor));
        $this->assertFalse($actor->can('update', $peer));
        $this->assertFalse($actor->can('lock', $peer));
    }

    public function test_admin_can_manage_lower_level_user_with_permission(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('ctv', 'web');
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $actor->assignRole('admin');
        $target->assignRole('ctv');
        $actor->givePermissionTo([
            AppPermission::UsersUpdate->value,
            AppPermission::UsersLock->value,
            AppPermission::UsersManageRoles->value,
        ]);

        $this->assertTrue($actor->can('update', $target));
        $this->assertTrue($actor->can('lock', $target));
        $this->assertTrue($actor->can('updateRolePermission', $target));
    }

    private function runMiddlewareAs(User $user, string ...$permissions): mixed
    {
        $request = Request::create('/admin/test');
        $request->setUserResolver(fn (): User => $user);

        return app(RequirePermission::class)->handle(
            $request,
            fn () => response('allowed'),
            ...$permissions,
        );
    }
}
