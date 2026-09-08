<?php
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
return new class extends Migration {
    public function up(): void {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['nicks.create','nro-accounts.view','nro-accounts.manage','item-listings.view','item-listings.manage','item-orders.view','item-orders.reconcile','nro-workers.manage','nro-settings.manage'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (Role::where('guard_name', 'web')->whereIn('name', ['admin','super-admin'])->get() as $role) $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down(): void { /* Keep existing grants when rolling back. */ }
};
