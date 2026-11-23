<?php

declare(strict_types=1);

namespace App\Setup;

/** The option file the MySQL backup reads its connection from, so that no password goes on a command line. */
final class MysqlOptionFile
{
    /** @param array<string, mixed> $connection a database.connections entry */
    public static function contents(array $connection, string $tool): string
    {
        $client = [
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (string) ($connection['port'] ?? ''),
            'user' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'socket' => (string) ($connection['unix_socket'] ?? ''),
        ];
        $lines = ['[client]'];
        foreach (array_filter($client, fn (string $value, string $name): bool => $value !== '' || $name === 'password', ARRAY_FILTER_USE_BOTH) as $name => $value) {
            $lines[] = $name.'='.self::quote($value);
        }
        // MySQL's own client needs it to sign in to a server without TLS as a caching_sha2_password user; MariaDB's warns at it.
        if (! self::isMariaDb($tool)) {
            $lines[] = 'loose-get-server-public-key=1';
            // Whatever the driver, as the file outlives a first run that could not reach the server: MySQL's mysqldump otherwise reads information_schema.COLUMN_STATISTICS, which a MariaDB server does not have.
            $lines[] = '[mysqldump]';
            $lines[] = 'loose-column-statistics=0';
        }
        // Read by MariaDB's clients alone, which refuse a MySQL server's self-signed certificate by default.
        $lines[] = '[client-mariadb]';
        $lines[] = 'ssl-verify-server-cert=0';

        return implode("\n", $lines)."\n";
    }

    /** Double-quoted, the one form both clients read every character back from. */
    public static function quote(string $value): string
    {
        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
    }

    /** A `mysqldump` on a MariaDB host is often a link to mariadb-dump. */
    public static function isMariaDb(string $tool): bool
    {
        $real = @realpath($tool);

        return str_contains(basename($real === false ? $tool : $real), 'mariadb');
    }

    /** Through a file beside it at mode 0600, renamed into place. */
    public static function write(string $path, string $contents): void
    {
        $temp = PrivateFile::temp(dirname($path), basename($path).'.', $contents, 0o600);

        try {
            PrivateFile::rename($temp, $path);
        } finally {
            @unlink($temp);
        }
    }
}
