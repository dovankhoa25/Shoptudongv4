<?php

use App\Enums\Permission as AppPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(AppPermission::ChatsViewAll->value, 'web');

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', AppPermission::ChatsViewAll->value)
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            Role::query()
                ->where('guard_name', 'web')
                ->whereIn('name', ['admin', 'super-admin'])
                ->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
