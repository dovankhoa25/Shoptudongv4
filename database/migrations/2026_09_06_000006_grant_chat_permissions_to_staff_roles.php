<?php

use App\Enums\Permission as AppPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'super-admin' => [
            AppPermission::ChatsView->value,
            AppPermission::ChatsReply->value,
            AppPermission::ChatsAssign->value,
            AppPermission::ChatsManage->value,
        ],
        'admin' => [
            AppPermission::ChatsView->value,
            AppPermission::ChatsReply->value,
            AppPermission::ChatsAssign->value,
            AppPermission::ChatsManage->value,
        ],
        'ctv' => [
            AppPermission::ChatsView->value,
            AppPermission::ChatsReply->value,
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_unique(array_merge(...array_values(self::ROLE_PERMISSIONS))) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first()
                ?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first()
                ?->revokePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
