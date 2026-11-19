<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\CopyDatabaseCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The fixtures are SHOW CREATE TABLE output from MySQL 8.4, which escapes a newline in a comment. */
class CopyDatabaseAutoIncrementOptionTest extends TestCase
{
    private const TRICKY_COLUMNS = <<<'SQL'
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `label` varchar(50) NOT NULL DEFAULT ') AUTO_INCREMENT=5' COMMENT 'line one\n) AUTO_INCREMENT=999 line two',
          PRIMARY KEY (`id`)
        SQL;

    /** @return array<string, array{string, ?string}> */
    public static function tables(): array
    {
        $options = ") ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='x AUTO_INCREMENT=7'";

        return [
            'the option, not a comment or default spelling it' => ["CREATE TABLE `tricky` (\n".self::TRICKY_COLUMNS."\n".$options, '42'],
            'ANSI_QUOTES' => ["CREATE TABLE \"tricky\" (\n".str_replace('`', '"', self::TRICKY_COLUMNS)."\n".$options, '42'],
            'no id handed out yet' => ["CREATE TABLE `fresh` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci", null],
            'no counter' => ["CREATE TABLE `plain` (\n  `code` varchar(10) NOT NULL,\n  PRIMARY KEY (`code`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci", null],
        ];
    }

    #[DataProvider('tables')]
    public function test_it_reads_the_table_option(string $createTable, ?string $expected): void
    {
        $this->assertSame($expected, CopyDatabaseCommand::autoIncrementOption($createTable));
    }
}
