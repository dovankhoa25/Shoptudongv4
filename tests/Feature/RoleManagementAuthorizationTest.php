<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (AppPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_admin_cannot_manage_super_admin_role(): void
    {
        $actor = $this->userWithRoleAndPermission('admin', AppPermission::RolesManage);
        $superAdminRole = Role::findOrCreate('super-admin', 'web');

        $this->actingAs($actor)
            ->getJson("/admin/roles/{$superAdminRole->id}/permissions")
            ->assertForbidden();
    }

    public function test_role_permission_screen_only_returns_semantic_permissions(): void
    {
        $actor = $this->userWithRoleAndPermission('admin', AppPermission::RolesManage);
        $ctvRole = Role::findOrCreate('ctv', 'web');
        Permission::findOrCreate('admin.legacy.route', 'web');

        $response = $this->actingAs($actor)
            ->getJson("/admin/roles/{$ctvRole->id}/permissions")
            ->assertOk();

        $this->assertSame(
            [AppPermission::RolesManage->value],
            collect($response->json('all_permissions'))->pluck('name')->all(),
        );
        $response->assertJsonMissing(['name' => 'admin.legacy.route']);
    }

    public function test_legacy_route_permission_cannot_be_assigned_from_role_api(): void
    {
        $actor = $this->userWithRoleAndPermission('admin', AppPermission::RolesManage);
        $ctvRole = Role::findOrCreate('ctv', 'web');
        $legacyPermission = Permission::findOrCreate('admin.legacy.route', 'web');

        $this->actingAs($actor)
            ->postJson("/admin/roles/{$ctvRole->id}/permissions/update", [
                'permissions' => [$legacyPermission->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_role_permissions_can_be_assigned_and_unchecked_permissions_are_removed(): void
    {
        $actor = $this->userWithRoleAndPermission('admin', AppPermission::RolesManage);
        $actor->givePermissionTo(AppPermission::AnalyticsView->value);
        $ctvRole = Role::findOrCreate('ctv', 'web');
        $ctvRole->givePermissionTo(AppPermission::RolesManage->value);

        $analyticsPermission = Permission::findByName(AppPermission::AnalyticsView->value, 'web');

        $this->actingAs($actor)
            ->postJson("/admin/roles/{$ctvRole->id}/permissions/update", [
                'permissions' => [$analyticsPermission->id],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Đã cập nhật quyền cho vai trò.')
            ->assertJsonPath('role_permissions.0', $analyticsPermission->id);

        $ctvRole->refresh();
        $this->assertTrue($ctvRole->hasPermissionTo(AppPermission::AnalyticsView->value));
        $this->assertFalse($ctvRole->hasPermissionTo(AppPermission::RolesManage->value));
    }

    public function test_updating_role_keeps_permissions_actor_cannot_manage_and_legacy_permissions(): void
    {
        $actor = $this->userWithRoleAndPermission('admin', AppPermission::RolesManage);
        $ctvRole = Role::findOrCreate('ctv', 'web');
        $legacyPermission = Permission::findOrCreate('admin.legacy.route', 'web');
        $ctvRole->givePermissionTo([
            AppPermission::RolesManage->value,
            AppPermission::AnalyticsView->value,
            $legacyPermission,
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/admin/roles/{$ctvRole->id}/permissions")
            ->assertOk()
            ->assertJsonPath('role_permissions.0', Permission::findByName(AppPermission::RolesManage->value, 'web')->id)
            ->assertJsonMissing(['name' => 'admin.legacy.route']);

        $this->assertSame(
            [AppPermission::AnalyticsView->value],
            collect($response->json('locked_permissions'))->pluck('name')->all(),
        );

        $this->actingAs($actor)
            ->postJson("/admin/roles/{$ctvRole->id}/permissions/update", [
                'permissions' => [],
            ])
            ->assertOk();

        $ctvRole->refresh();
        $this->assertFalse($ctvRole->hasPermissionTo(AppPermission::RolesManage->value));
        $this->assertTrue($ctvRole->hasPermissionTo(AppPermission::AnalyticsView->value));
        $this->assertTrue($ctvRole->hasPermissionTo('admin.legacy.route'));
    }

    private function userWithRoleAndPermission(string $role, AppPermission $permission): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->givePermissionTo($permission->value);

        return $user;
    }
}
