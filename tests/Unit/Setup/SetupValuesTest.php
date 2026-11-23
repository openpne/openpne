<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use App\Setup\BackupLine;
use App\Setup\EnvValue;
use App\Setup\MysqlOptionFile;
use App\Setup\ProxyList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SetupValuesTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function envValues(): array
    {
        return [
            'line break' => ["a\nb", 'env_control'],
            'carriage return' => ["a\rb", 'env_control'],
            'tab' => ["a\tb", 'env_control'],
            'nul' => ["a\0b", 'env_control'],
            'delete' => ["a\x7fb", 'env_control'],
            'expansion' => ['a${HOME}', 'env_expansion'],
            'unclosed expansion' => ['a${b', 'env_expansion'],
            'true' => ['TRUE', 'env_keyword'],
            'null in parentheses' => ['(Null)', 'env_keyword'],
            'empty' => ['empty', 'env_keyword'],
            'double-quoted' => ['"abc"', 'env_quoted'],
            'single-quoted' => ["'abc'", 'env_quoted'],
            'two quotes' => ['""', 'env_quoted'],
            'double quote and backslash' => ['a"b\\c', 'env_quote_mix'],
            'double quote and single quote' => ['a"b\'c', 'env_quote_mix'],
            'one double quote after equals' => ['ab="cd', 'env_quote_equals'],
            'ending in equals and a double quote' => ['p="', 'env_quote_equals'],
            'two double quotes after equals' => ['a="b"', null],
            'dollar' => ['a$b', null],
            'half a keyword' => ['(true', null],
            'one double quote' => ['say "hi', null],
            'hash' => ['p#ss', null],
            'backslash alone' => ['a\\b', null],
            'Japanese' => ['日本語の SNS', null],
            'empty string' => ['', null],
        ];
    }

    #[DataProvider('envValues')]
    public function test_env_values_that_would_not_read_back_are_refused(string $value, ?string $problem): void
    {
        $this->assertSame($problem, EnvValue::problem($value));
    }

    /** @return array<string, array{string, string|null}> */
    public static function proxyLists(): array
    {
        return [
            'address' => ['203.0.113.10', null],
            'ranges with spaces' => ['203.0.113.0/24, 2001:db8::/32', null],
            'widest IPv4' => ['10.0.0.0/8', null],
            'widest IPv6' => ['2001:db8::/16', null],
            'star' => ['*', 'proxy_everyone'],
            'two stars' => ['**', 'proxy_everyone'],
            'slash zero' => ['0.0.0.0/0', 'proxy_everyone'],
            'slash double zero' => ['203.0.113.0/00', 'proxy_everyone'],
            'too wide IPv4' => ['10.0.0.0/7', 'proxy_too_wide'],
            'too wide IPv6' => ['2001:db8::/15', 'proxy_too_wide'],
            'leading zero' => ['203.0.113.0/024', 'proxy_not_address'],
            'beyond IPv4' => ['203.0.113.0/33', 'proxy_not_address'],
            'keyword' => ['REMOTE_ADDR', 'proxy_not_address'],
            'private keyword' => ['PRIVATE_SUBNETS', 'proxy_not_address'],
            'host name' => ['proxy.example.com', 'proxy_not_address'],
            'empty entry' => ['203.0.113.10,', 'proxy_not_address'],
        ];
    }

    #[DataProvider('proxyLists')]
    public function test_proxy_lists_trust_named_addresses_only(string $value, ?string $problem): void
    {
        $this->assertSame($problem, ProxyList::problem($value));
    }

    public function test_a_proxy_list_is_written_without_the_spaces_typed(): void
    {
        $this->assertSame('203.0.113.0/24,2001:db8::/32', ProxyList::normalize(' 203.0.113.0/24 ,2001:db8::/32 '));
    }

    public function test_option_file_values_are_double_quoted_with_quotes_and_controls_escaped(): void
    {
        // Escaping the backslash alone leaves `#` after a `"` a comment to both clients.
        $this->assertSame('"a\\"b#c"', MysqlOptionFile::quote('a"b#c'));
        $this->assertSame('"x\\\\y\\nz\\r\\t"', MysqlOptionFile::quote("x\\y\nz\r\t"));
        $this->assertSame('""', MysqlOptionFile::quote(''));
    }

    public function test_the_option_file_holds_the_connection_and_what_each_client_needs(): void
    {
        $connection = ['driver' => 'mariadb', 'host' => 'db.example.com', 'port' => 3306, 'username' => 'site', 'password' => 'p"w', 'unix_socket' => ''];

        $this->assertSame(
            "[client]\nhost=\"db.example.com\"\nport=\"3306\"\nuser=\"site\"\npassword=\"p\\\"w\"\nloose-get-server-public-key=1\n[mysqldump]\nloose-column-statistics=0\n[client-mariadb]\nssl-verify-server-cert=0\n",
            MysqlOptionFile::contents($connection, '/usr/bin/mysqldump'),
        );
        $this->assertStringNotContainsString('column-statistics', MysqlOptionFile::contents(['driver' => 'mysql'] + $connection, '/usr/bin/mysqldump'));
        foreach (['get-server-public-key', 'column-statistics'] as $option) {
            $this->assertStringNotContainsString($option, MysqlOptionFile::contents($connection, '/usr/bin/mariadb-dump'));
        }
        $this->assertStringContainsString("socket=\"/run/mysqld.sock\"\n", MysqlOptionFile::contents(['unix_socket' => '/run/mysqld.sock'] + $connection, '/usr/bin/mysqldump'));
    }

    public function test_a_mysqldump_linked_to_mariadb_dump_is_mariadb(): void
    {
        $directory = sys_get_temp_dir().'/openpne-setup-link-'.bin2hex(random_bytes(4));
        mkdir($directory);
        touch($directory.'/mariadb-dump');
        symlink($directory.'/mariadb-dump', $directory.'/mysqldump');

        try {
            $this->assertTrue(MysqlOptionFile::isMariaDb($directory.'/mysqldump'));
            $this->assertFalse(MysqlOptionFile::isMariaDb('/nowhere/mysqldump'));
        } finally {
            unlink($directory.'/mysqldump');
            unlink($directory.'/mariadb-dump');
            rmdir($directory);
        }
    }

    /** @return array<string, array{string}> */
    public static function unsafeCharacters(): array
    {
        return array_combine(
            ['single quote', 'double quote', 'bang', 'dollar', 'backtick', 'backslash', 'percent', 'line break', 'tab'],
            array_map(fn (string $c): array => ["/home/u/si{$c}te"], ["'", '"', '!', '$', '`', '\\', '%', "\n", "\t"]),
        );
    }

    #[DataProvider('unsafeCharacters')]
    public function test_a_path_a_shell_or_cron_would_read_is_not_put_in_a_line(string $path): void
    {
        $this->assertFalse(BackupLine::safe('/usr/bin/php8.3', $path));
    }

    public function test_spaces_japanese_and_brackets_are_safe_in_a_line(): void
    {
        $this->assertTrue(BackupLine::safe('/home/u/my site/日本語 (1)&2'));
    }

    public function test_the_backup_lines_write_aside_and_move_over_the_last_copy(): void
    {
        $this->assertSame(
            "cd '/h/site' && '/usr/bin/php8.3' artisan openpne:backup-sqlite '/h/openpne-data/backup.sqlite'",
            BackupLine::sqlite('/usr/bin/php8.3', '/h/site', '/h/openpne-data'),
        );
        $this->assertSame(
            "rm -f '/h/openpne-data/backup.sql.new' && '/usr/bin/mysqldump' --defaults-extra-file='/h/openpne-data/mysql-backup.cnf'"
            ." --single-transaction --no-tablespaces 'site_db' > '/h/openpne-data/backup.sql.new' && mv '/h/openpne-data/backup.sql.new' '/h/openpne-data/backup.sql'",
            BackupLine::mysql('/usr/bin/mysqldump', '/h/openpne-data/mysql-backup.cnf', 'site_db', '/h/openpne-data'),
        );
        $this->assertSame(
            "*/5 * * * * cd '/h/site' && '/usr/bin/php8.3' artisan schedule:run > /dev/null 2>&1",
            BackupLine::cron('/usr/bin/php8.3', '/h/site'),
        );
    }
}
