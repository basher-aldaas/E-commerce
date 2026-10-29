<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Users\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Admin account
        $admin = User::firstOrCreate(
            ['email' => 'admin@system.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('password123'),
            ]
        );

        $admin->assignRole(
            \Spatie\Permission\Models\Role::findByName('admin', 'api')
        );
        // 2. Create regular User account
        $user = User::firstOrCreate(
            ['email' => 'user@system.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password123'),
            ]
        );

        $user->assignRole(
            \Spatie\Permission\Models\Role::findByName('user', 'api')
        );
        // 3. Create 20 test users
        for ($i = 1; $i <= 20; $i++) {
            $testUser = User::firstOrCreate(
                ['email' => "customer{$i}@system.com"],
                [
                    'name' => "Customer {$i}",
                    'password' => bcrypt('password123'),
                ]
            );

            $testUser->assignRole(
                \Spatie\Permission\Models\Role::findByName('user', 'api')
            );        }
    }
}
