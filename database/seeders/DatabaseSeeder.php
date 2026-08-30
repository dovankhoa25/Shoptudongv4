<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        // AdminSeeder tự gọi PermissionFromRoutesSeeder trước khi gán super-admin.
        $this->call([
            AdminSeeder::class,
        ]);
    }
}
