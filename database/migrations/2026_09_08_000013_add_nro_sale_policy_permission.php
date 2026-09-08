<?php
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('nro-sale-policy.manage', 'web');
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['admin', 'super-admin'])->get() as $role) $role->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down(): void { /* Preserve explicit permission grants. */ }
};
