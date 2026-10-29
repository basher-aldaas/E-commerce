<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Users\Database\Seeders\UsersDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UsersDatabaseSeeder::class,
        ]);
    }
}
