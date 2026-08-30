<?php

namespace Database\Seeders;

use App\Enums\Permission as AppPermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionFromRoutesSeeder extends Seeder
{
    /**
     * Ánh xạ quyền route cũ sang quyền nghiệp vụ mới.
     * Quyền cũ được giữ để rollback, nhưng không còn dùng để bảo vệ route.
     *
     * @var array<string, AppPermission>
     */
    private const LEGACY_PERMISSION_MAP = [
        'admin.' => AppPermission::DashboardView,
        'admin.analytics.index' => AppPermission::AnalyticsView,
        'admin.games.accounts.index' => AppPermission::NicksView,
        'admin.games.accounts.show' => AppPermission::NicksView,
        'admin.games.accounts.history.index' => AppPermission::NicksView,
        'admin.games.accounts.create' => AppPermission::NicksManage,
        'admin.games.accounts.store' => AppPermission::NicksManage,
        'admin.games.accounts.update' => AppPermission::NicksManage,
        'admin.games.accounts.destroy' => AppPermission::NicksManage,
        'admin.games.categories.attributes' => AppPermission::AttributesView,
        'admin.services.orders.index' => AppPermission::ServiceOrdersView,
        'admin.services.orders.receiver' => AppPermission::ServiceOrdersView,
        'admin.services.orders.accept' => AppPermission::ServiceOrdersProcess,
        'admin.services.orders.receiver.complete' => AppPermission::ServiceOrdersProcess,
        'admin.services.orders.receiver.cancel' => AppPermission::ServiceOrdersProcess,
        'admin.transactions.index' => AppPermission::TransactionsView,
        'admin.withdrawals.index' => AppPermission::WithdrawalsView,
        'admin.withdrawals.store' => AppPermission::WithdrawalsCreate,
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AppPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $allSemanticPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', AppPermission::values())
            ->get();

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        // Role admin cũ vốn được bypass toàn bộ route, nên phải có đủ quyền mới trước khi đổi middleware.
        $superAdmin->syncPermissions($allSemanticPermissions);
        $admin->givePermissionTo($allSemanticPermissions);

        $this->mapLegacyRolePermissions();
        $this->mapLegacyDirectUserPermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function mapLegacyRolePermissions(): void
    {
        Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name,guard_name')
            ->get()
            ->each(function (Role $role): void {
                $semanticPermissions = $role->permissions
                    ->pluck('name')
                    ->map(fn (string $name): ?string => (self::LEGACY_PERMISSION_MAP[$name] ?? null)?->value)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($semanticPermissions !== []) {
                    $role->givePermissionTo($semanticPermissions);
                }
            });
    }

    private function mapLegacyDirectUserPermissions(): void
    {
        User::query()
            ->whereHas('permissions', fn ($query) => $query->whereIn('name', array_keys(self::LEGACY_PERMISSION_MAP)))
            ->with('permissions:id,name,guard_name')
            ->each(function (User $user): void {
                $semanticPermissions = $user->permissions
                    ->pluck('name')
                    ->map(fn (string $name): ?string => (self::LEGACY_PERMISSION_MAP[$name] ?? null)?->value)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($semanticPermissions !== []) {
                    $user->givePermissionTo($semanticPermissions);
                }
            });
    }
}
