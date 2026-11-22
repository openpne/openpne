<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Database\DatabaseServerProbe;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseServerProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge('probe_test');

        parent::tearDown();
    }

    public function test_a_sqlite_connection_has_no_server_and_is_not_opened(): void
    {
        config(['database.connections.probe_test' => ['driver' => 'sqlite', 'database' => '/nowhere/database.sqlite', 'prefix' => '']]);

        $this->assertNull((new DatabaseServerProbe)->behind('probe_test'));
    }

    public function test_the_mysql_test_server_is_one_openpne_runs_on(): void
    {
        $this->requireMysql();

        $server = (new DatabaseServerProbe)->behind((string) config('database.default'));

        $this->assertNotNull($server);
        $this->assertSame([false, true], [$server->mariaDb, $server->supported()]);
    }

    public function test_a_server_refusing_only_the_connections_collation_is_asked_again_without_one(): void
    {
        $this->requireMysql();
        // MariaDB's own name, which MySQL refuses with the 1273 MariaDB 10 gives for utf8mb4_0900_bin.
        config(['database.connections.probe_test' => [...config('database.connections.'.config('database.default')), 'collation' => 'utf8mb4_nopad_bin']]);

        $server = (new DatabaseServerProbe)->behind('probe_test');

        $this->assertSame([false, true], [$server?->mariaDb, $server?->supported()]);
        $this->assertArrayNotHasKey('openpne_server_probe', DB::getConnections());
    }

    #[DataProvider('mysqlFamilyDrivers')]
    public function test_a_server_that_cannot_be_reached_throws_what_connecting_threw(string $driver): void
    {
        config(['database.connections.probe_test' => [
            'driver' => $driver, 'host' => '127.0.0.1', 'port' => 1, 'database' => 'site_db',
            'username' => 'site', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin', 'prefix' => '',
        ]]);

        $this->expectException(PDOException::class);
        (new DatabaseServerProbe)->behind('probe_test');
    }

    /** @return array<string, array{string}> */
    public static function mysqlFamilyDrivers(): array
    {
        return ['mysql' => ['mysql'], 'mariadb' => ['mariadb']];
    }

    private function requireMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Needs the MySQL test connection.');
        }
    }
}
