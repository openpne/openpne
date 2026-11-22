<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Facades\DB;
use PDOException;

/** See docs/internals/runtime.md, "Database server". */
class DatabaseServerProbe
{
    private const UNKNOWN_COLLATION = 1273;

    private const PROBE = 'openpne_server_probe';

    /** Null for a connection that is not MySQL or MariaDB; throws what connecting throws, unless the server refused only the connection's collation. */
    public function behind(string $connection): ?DatabaseServer
    {
        $db = DB::connection($connection);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }
        try {
            $db->getPdo();
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== self::UNKNOWN_COLLATION) {
                throw $e;
            }

            config(['database.connections.'.self::PROBE => [...$db->getConfig(), 'name' => self::PROBE, 'url' => null, 'collation' => null]]);
            try {
                return DatabaseServer::fromVersion((string) DB::connection(self::PROBE)->scalar('select version()'));
            } finally {
                DB::purge(self::PROBE);
            }
        }

        return DatabaseServer::fromVersion((string) $db->scalar('select version()'));
    }
}
