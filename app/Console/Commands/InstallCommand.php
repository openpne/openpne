<?php

namespace App\Console\Commands;

use Database\Seeders\InstallSeeder;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'openpne:install';

    protected $description = 'Create the schema and the initial data of a new site';

    public function handle(): int
    {
        // Both return FAILURE rather than throwing when refused, so each exit code is checked.
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->call('db:seed', ['--class' => InstallSeeder::class, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->info('OpenPNE is installed. Next: php artisan openpne:admin:create <username> creates the first administrator.');

        return self::SUCCESS;
    }
}
