<?php

namespace Tests\Feature\Console;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesDatabaseServer;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use FakesDatabaseServer;
    use RefreshDatabase;

    private string|false $lang;

    private string $testConnection;

    protected function setUp(): void
    {
        parent::setUp();

        // A refusal is in the shell's language, which a developer's machine may set to Japanese.
        $this->lang = getenv('LANG');
        putenv('LANG=en_US.UTF-8');
        $this->testConnection = (string) config('database.default');
    }

    protected function tearDown(): void
    {
        putenv($this->lang === false ? 'LANG' : "LANG={$this->lang}");
        // RefreshDatabase rolls back the default connection as it then stands, and migrates afresh where that one is outside its transaction.
        config(['database.default' => $this->testConnection]);

        parent::tearDown();
    }

    public function test_it_seeds_a_fresh_site_and_names_the_next_step(): void
    {
        $this->artisan('openpne:install')
            ->expectsOutputToContain('openpne:admin:create')
            ->assertSuccessful();

        foreach (['profiles', 'navigations', 'gadgets', 'banners'] as $table) {
            $this->assertTrue(DB::table($table)->exists(), "{$table} is seeded");
        }
    }

    public function test_a_second_run_succeeds_without_adding_rows(): void
    {
        $this->artisan('openpne:install')->assertSuccessful();
        $navigations = DB::table('navigations')->count();

        $this->artisan('openpne:install')
            ->expectsOutputToContain('already initialised')
            ->assertSuccessful();

        $this->assertSame($navigations, DB::table('navigations')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function refusedServers(): array
    {
        return [
            'MariaDB below the floor' => ['10.6.28-MariaDB-ubu2204', 'The database server is MariaDB 10.6.28, and OpenPNE runs on MariaDB 10.11 or later only.'],
            'MariaDB, which takes the mariadb driver' => ['11.4.13-MariaDB-ubu2404', 'The database server is MariaDB 11.4.13. Set DB_CONNECTION=mariadb in '],
            'MySQL, which takes the mysql driver' => ['8.4.13', 'The database server is MySQL 8.4.13. Set DB_CONNECTION=mysql in '],
        ];
    }

    #[DataProvider('refusedServers')]
    public function test_a_server_setup_refuses_stops_it_before_any_table(string $version, string $said): void
    {
        config([
            'database.connections.install_empty' => [...config('database.connections.sqlite'), 'url' => null, 'database' => ':memory:'],
            'database.default' => 'install_empty',
        ]);
        $this->fakeDatabaseServer($version);

        $this->artisan('openpne:install')->expectsOutputToContain($said)->assertFailed();

        $this->assertSame([], Schema::connection('install_empty')->getTableListing());
    }

    /** @return array<string, array{string, string}> */
    public static function urlOverrides(): array
    {
        return [
            'DB_CONNECTION is the server\'s, DB_URL is not' => ['mariadb', 'DB_CONNECTION=mariadb in %s matches it, but DB_URL there names the mysql driver, which overrides DB_CONNECTION. Begin DB_URL with mariadb://'],
            'neither is the server\'s' => ['mysql', 'Set DB_CONNECTION=mariadb in %s, then'],
        ];
    }

    #[DataProvider('urlOverrides')]
    public function test_both_the_driver_db_connection_names_and_the_one_db_url_sets_are_weighed(string $configured, string $said): void
    {
        config([
            'database.connections.install_url' => [...config('database.connections.'.$configured), 'url' => 'mysql://root@127.0.0.1:1/site_db'],
            'database.default' => 'install_url',
        ]);
        $this->fakeDatabaseServer('11.4.13-MariaDB-ubu2404');

        $this->artisan('openpne:install')->expectsOutputToContain(sprintf($said, $this->app->environmentFilePath()))->assertFailed();
    }

    public function test_a_refusal_is_in_the_shells_language_else_the_sites(): void
    {
        config([
            'database.connections.install_empty' => [...config('database.connections.sqlite'), 'url' => null, 'database' => ':memory:'],
            'database.default' => 'install_empty',
        ]);
        $this->fakeDatabaseServer('11.4.13-MariaDB-ubu2404');

        putenv('LANG=ja_JP.UTF-8');
        config(['app.locale' => 'en']);
        $this->artisan('openpne:install')->expectsOutputToContain('データベースのサーバーは MariaDB 11.4.13 です。')->assertFailed();

        putenv('LANG=C.UTF-8');
        config(['app.locale' => 'ja']);
        $this->artisan('openpne:install')->expectsOutputToContain('データベースのサーバーは MariaDB 11.4.13 です。')->assertFailed();

        config(['app.locale' => 'en']);
        $this->artisan('openpne:install')->expectsOutputToContain('The database server is MariaDB 11.4.13.')->assertFailed();
    }

    public function test_a_server_that_does_not_answer_is_left_to_migrate_to_report(): void
    {
        config([
            'database.connections.install_unreachable' => [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 1, 'database' => 'site_db'],
            'database.default' => 'install_unreachable',
        ]);

        $this->expectException(QueryException::class);
        $this->artisan('openpne:install')->run();
    }

    public function test_a_sqlite_file_that_is_missing_is_still_made_by_migrate(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'openpne-install-');
        unlink($path);
        config([
            'database.connections.install_file' => [...config('database.connections.sqlite'), 'url' => null, 'database' => $path],
            'database.default' => 'install_file',
        ]);

        try {
            $this->artisan('openpne:install')->assertSuccessful();

            $this->assertTrue(DB::connection('install_file')->table('navigations')->exists());
        } finally {
            DB::purge('install_file');
            @unlink($path);
        }
    }

    /** @return array<string, array{bool}> */
    public static function databasePresence(): array
    {
        return ['a database made for it' => [true], 'no database yet' => [false]];
    }

    #[DataProvider('databasePresence')]
    public function test_the_other_drivers_connection_to_the_test_server_is_refused_before_anything_is_made(bool $made): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs the MySQL or MariaDB test connection.');
        }
        $database = DB::connection()->getDatabaseName().'_install';
        // A session of its own, as DDL would commit the test's transaction.
        config(['database.connections.install_server' => [...DB::connection()->getConfig(), 'name' => 'install_server', 'url' => null, 'database' => null]]);
        $server = DB::connection('install_server');
        $server->statement("DROP DATABASE IF EXISTS `{$database}`");
        if ($made) {
            $server->statement("CREATE DATABASE `{$database}`");
        }
        config([
            'database.connections.install_other' => [...config('database.connections.'.($driver === 'mysql' ? 'mariadb' : 'mysql')), 'url' => null, 'database' => $database],
            'database.default' => 'install_other',
        ]);

        try {
            $this->artisan('openpne:install')
                ->expectsOutputToContain("Set DB_CONNECTION={$driver} in ".$this->app->environmentFilePath())
                ->assertFailed();

            $this->assertSame(0, (int) $server->scalar('select count(*) from information_schema.TABLES where TABLE_SCHEMA = ?', [$database]));
            $this->assertSame($made, (int) $server->scalar('select count(*) from information_schema.SCHEMATA where SCHEMA_NAME = ?', [$database]) === 1);
        } finally {
            $server->statement("DROP DATABASE IF EXISTS `{$database}`");
            DB::purge('install_other');
            DB::purge('install_server');
        }
    }
}
