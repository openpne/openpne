<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Migrates once per worker process and resets only data between methods: the source tables are raw
 * DDL, which commits and so cannot ride inside RefreshDatabase's transaction, and a per-method
 * migrate:fresh is the MySQL lane's long pole. Not for a test that drops or alters an app table;
 * those keep DatabaseMigrations.
 */
trait MigratesUpgradeTargetsOnce
{
    use DatabaseTruncation;

    protected function truncateDatabaseTables(): void
    {
        // Never touch RefreshDatabaseState off MySQL: on sqlite :memory: RefreshDatabase pairs that
        // flag with a PDO cache, and setting it alone leaves the next RefreshDatabase test on a
        // schemaless connection.
        if ($this->app->make('db')->connection()->getDriverName() !== 'mysql') {
            return;
        }

        // The shared flag, not a private one: a co-resident DatabaseMigrations test drops the schema
        // and clears it on teardown, and the sentinel table covers a drop that does not.
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

        // Reset after the test, not before: a following RefreshDatabase method would otherwise open
        // its transaction on these committed rows.
        $this->beforeApplicationDestroyed(function () {
            if (RefreshDatabaseState::$migrated && Schema::hasTable('openpne4_upgrade_state')) {
                $this->truncateTablesForAllConnections();
            }
        });
    }

    /** DELETE, not TRUNCATE: the targets include FK parents (files, members) that MySQL refuses to TRUNCATE. */
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
