<?php

namespace App\Console\Commands;

use App\Database\DatabaseServer;
use App\Database\DatabaseServerProbe;
use Database\Seeders\InstallSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'openpne:install';

    protected $description = 'Create the schema and the initial data of a new site';

    private const PROBE = 'openpne_install_probe';

    public function handle(DatabaseServerProbe $servers): int
    {
        $connection = DB::connection();
        $server = $this->server($servers, $connection->getConfig());
        if ($server?->supported() === false) {
            return $this->refuse('setup.db_mariadb', ['server' => $server->name(), 'path' => $this->laravel->environmentFilePath(), 'minimum' => DatabaseServer::MARIADB_MINIMUM]);
        }
        // The driver DB_CONNECTION names, as setup and check read it.
        if ($server !== null && $server->driver() !== config('database.connections.'.$connection->getName().'.driver')) {
            return $this->refuse('setup.db_driver', ['server' => $server->name(), 'driver' => $server->driver(), 'path' => $this->laravel->environmentFilePath()]);
        }
        // The driver migrate runs on, which a DB_URL scheme sets over DB_CONNECTION's.
        if ($server !== null && $server->driver() !== $connection->getDriverName()) {
            return $this->refuse('install.db_url', ['server' => $server->name(), 'live' => $connection->getDriverName(), 'driver' => $server->driver(), 'path' => $this->laravel->environmentFilePath()]);
        }

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

    /**
     * Asked through a session naming no database, as migrate creates a database that is missing (docs/internals/runtime.md, "Database server").
     * Null for a connection other than MySQL or MariaDB, or where the probe fails, leaving the failure to migrate to report.
     *
     * @param  array<string, mixed>  $config
     */
    private function server(DatabaseServerProbe $servers, array $config): ?DatabaseServer
    {
        config(['database.connections.'.self::PROBE => [...$config, 'name' => self::PROBE, 'url' => null, 'database' => null]]);
        try {
            return $servers->behind(self::PROBE);
        } catch (Throwable) {
            return null;
        } finally {
            DB::purge(self::PROBE);
        }
    }

    /** @param array<string, string> $replace */
    private function refuse(string $key, array $replace): int
    {
        $locale = CheckCommand::shellLocale() ?? (config('app.locale') === 'ja' ? 'ja' : 'en');
        $this->error(OutputFormatter::escape(CheckCommand::translate($key, $replace, $locale)));

        return self::FAILURE;
    }
}
