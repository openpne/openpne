<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * A second connection to the same database, for a test that needs two transactions in flight. Only
 * meaningful off RefreshDatabase, whose wrapping transaction the other connection could not see into.
 */
trait OpensSecondConnection
{
    protected const SECOND = 'second';

    protected function openSecondConnection(int $lockWaitSeconds = 1): void
    {
        $default = config('database.default');
        config(['database.connections.'.self::SECOND => config("database.connections.{$default}")]);

        DB::connection(self::SECOND)->statement("SET SESSION innodb_lock_wait_timeout = {$lockWaitSeconds}");
    }

    protected function closeSecondConnection(): void
    {
        DB::purge(self::SECOND);
    }

    /**
     * Run a write as if from another request: every model and query builder inside resolves the
     * second connection.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    protected function onSecondConnection(callable $write): mixed
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::SECOND);

        try {
            return $write();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
