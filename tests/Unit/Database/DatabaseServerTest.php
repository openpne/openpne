<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\DatabaseServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseServerTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function versions(): array
    {
        // VERSION() on the official images.
        return [
            'MySQL 8.4' => ['8.4.13', 'MySQL 8.4.13', true],
            'MariaDB 10.6' => ['10.6.28-MariaDB-ubu2204', 'MariaDB 10.6.28', false],
            'MariaDB 10.10' => ['10.10.7-MariaDB', 'MariaDB 10.10.7', false],
            'MariaDB 10.11' => ['10.11.19-MariaDB-ubu2204', 'MariaDB 10.11.19', true],
            'MariaDB 11.4' => ['11.4.13-MariaDB-ubu2404', 'MariaDB 11.4.13', true],
            'MariaDB 11.8' => ['11.8.9-MariaDB-ubu2404', 'MariaDB 11.8.9', true],
        ];
    }

    #[DataProvider('versions')]
    public function test_the_version_names_the_server_and_whether_openpne_runs_on_it(string $version, string $name, bool $supported): void
    {
        $server = DatabaseServer::fromVersion($version);

        $this->assertSame([$name, $supported], [$server->name(), $server->supported()]);
    }
}
