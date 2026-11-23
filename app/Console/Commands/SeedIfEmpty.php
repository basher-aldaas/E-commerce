<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Users\Models\User;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Seeds the demo data once, on a fresh database. Used by the Docker entrypoint so
 * that restarting the containers never adds data or images again. To reseed on
 * purpose, run `php artisan db:seed` (or `migrate:fresh --seed`) yourself.
 */
class SeedIfEmpty extends Command
{
    protected $signature = 'app:seed-if-empty';

    protected $description = 'Run the database seeders only when the users table is empty';

    public function handle(): int
    {
        if (! Schema::hasTable('users')) {
            $this->error('The users table does not exist. Run the migrations first.');

            return SymfonyCommand::FAILURE;
        }

        if (User::query()->exists()) {
            $this->info('Database already has data; seeding skipped.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info('Empty database; seeding demo data.');

        return $this->call('db:seed', ['--force' => true]);
    }
}
