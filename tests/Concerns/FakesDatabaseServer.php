<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Database\DatabaseServer;
use App\Database\DatabaseServerProbe;

trait FakesDatabaseServer
{
    /** The connection named, or every connection where none is, reads as reaching a server whose VERSION() is $version, without being opened. */
    private function fakeDatabaseServer(string $version, ?string $connection = null): void
    {
        $this->app->instance(DatabaseServerProbe::class, new class($version, $connection) extends DatabaseServerProbe
        {
            public function __construct(private readonly string $version, private readonly ?string $connection) {}

            public function behind(string $connection): ?DatabaseServer
            {
                return $this->connection === null || $this->connection === $connection
                    ? DatabaseServer::fromVersion($this->version)
                    : parent::behind($connection);
            }
        });
    }
}
