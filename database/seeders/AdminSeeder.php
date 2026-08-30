<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionFromRoutesSeeder::class);

        $superAdminUserId = config('permission.super_admin_user_id');

        if (! $superAdminUserId) {
            $this->command?->warn('Chưa đặt SUPER_ADMIN_USER_ID; không tự động nâng quyền user nào.');

            return;
        }

        $user = User::query()->findOrFail($superAdminUserId);
        $superAdminRole = Role::findByName('super-admin', 'web');

        $user->assignRole($superAdminRole);
    }
}
