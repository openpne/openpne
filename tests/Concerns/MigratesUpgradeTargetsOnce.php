<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Isolation for the MySQL-only OpenPNE 3 upgrade SQL tests without a per-method
 * migrate:fresh. `DatabaseMigrations` drops+creates every migration (and rolls it back)
 * for each of ~200 upgrade methods — the mysql CI lane's long pole, growing with every
 * migration added. These tests create their OpenPNE 3 source tables (raw DDL, which
 * implicitly commits and so cannot ride inside RefreshDatabase's transaction) and copy
 * set-based into the app target tables, leaving the app *schema* stable across methods.
 * So: migrate once per worker process, then reset only the data between methods.
 *
 * Reset uses DELETE, not TRUNCATE: the targets include FK parents (files, members) that
 * MySQL refuses to TRUNCATE — App\Upgrade\Runner\UpgradeRunner::reset() does the same.
 *
 * NOT for tests that structurally mutate the app schema (DROP/ALTER an app table, directly
 * or via the file_bin FK rewire when a run migrates `files`). Those keep DatabaseMigrations:
 * FileBinMigrationTest, SourcePreflightTest, VerifierFileBinTest.
 */
trait MigratesUpgradeTargetsOnce
{
    use DatabaseTruncation;

    protected function truncateDatabaseTables(): void
    {
        // MySQL-only suite: on any other driver each test's setUp calls markTestSkipped, so
        // there is nothing to migrate or reset. Return before touching RefreshDatabaseState —
        // on sqlite :memory: RefreshDatabase owns it *and* an in-memory PDO cache; setting
        // $migrated=true here without populating that cache leaves a following RefreshDatabase
        // test to open its transaction on a schemaless fresh :memory: connection.
        if ($this->app->make('db')->connection()->getDriverName() !== 'mysql') {
            return;
        }

        // A co-resident DatabaseMigrations test (same worker — --functional interleaves a
        // class's methods across workers) drops the schema and clears
        // RefreshDatabaseState::$migrated in its teardown rollback. Sharing that flag — not a
        // private one — is what lets us notice and re-migrate; the sentinel also covers any
        // path that drops the schema without clearing the flag.
        if (RefreshDatabaseState::$migrated && ! Schema::hasTable('openpne4_upgrade_state')) {
            RefreshDatabaseState::$migrated = false;
        }

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        // Cache the table list now, while only the migrated app tables exist: the subclass
        // setUp creates its OpenPNE 3 source tables *after* this runs, so they never enter
        // the reset set (and are dropped by the subclass teardown regardless).
        $database = $this->app->make('db');
        foreach ($this->connectionsToTruncate() as $connectionName) {
            $this->getAllTablesForConnection($database->connection($connectionName), $connectionName);
        }

        // Reset AFTER the test, not before: a following RefreshDatabase method reuses the
        // migrated schema and would open its transaction on our committed rows. Clearing here
        // hands both it and the next upgrade method an empty database.
        $this->beforeApplicationDestroyed(function () {
            if (RefreshDatabaseState::$migrated && Schema::hasTable('openpne4_upgrade_state')) {
                $this->truncateTablesForAllConnections();
            }
        });
    }

    /** Reset app target data with DELETE + AUTO_INCREMENT rewind (see the class note on TRUNCATE). */
    protected function truncateTablesForConnection(ConnectionInterface $connection, ?string $name): void
    {
        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();

        $isMysql = $connection->getDriverName() === 'mysql';
        $exceptTables = $this->exceptTables($connection, $name); // includes the migrations table

        (new Collection($this->getAllTablesForConnection($connection, $name)))
            ->reject(fn (array $table) => $this->tableExistsIn($table, $exceptTables))
            ->each(function (array $table) use ($connection, $isMysql) {
                $connection->withoutTablePrefix(function ($connection) use ($table, $isMysql) {
                    $query = $connection->table($table['schema_qualified_name']);

                    if (! $query->exists()) {
                        return;
                    }

                    $query->delete();

                    if ($isMysql) {
                        $connection->statement("ALTER TABLE `{$table['name']}` AUTO_INCREMENT = 1");
                    }
                });
            });

        $connection->setEventDispatcher($dispatcher);
    }
}
