<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserAuthProvider;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::withTrashed()->updateOrCreate(
            ['username' => 'testuser'],
            [
                'email' => 'testuser@dovankhoa.vn',
                'password' => 'Test@123456',
                'status' => User::STATUS_ACTIVE,
                'locked_until' => null,
                'locked_reason' => null,
                'email_verified_at' => now(),
                'deleted_at' => null,
            ],
        );

        UserAuthProvider::updateOrCreate(
            [
                'provider' => 'password',
                'provider_id' => $user->username,
            ],
            [
                'user_id' => $user->id,
                'provider_email' => $user->email,
                'provider_username' => $user->username,
                'is_enabled' => true,
            ],
        );

        $this->command?->info('Test user seeded: testuser / Test@123456');
    }
}
