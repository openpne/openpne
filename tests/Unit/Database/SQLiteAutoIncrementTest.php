<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\SQLiteSchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SQLiteAutoIncrementTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function tables(): array
    {
        return [
            'Laravel' => ['CREATE TABLE "probe" ("id" integer primary key autoincrement not null, "label" varchar not null)', true],
            'by hand' => ["CREATE TABLE probe (\n  id INTEGER PRIMARY KEY AUTOINCREMENT,\n  label TEXT DEFAULT 'it''s'\n)", true],
            'a default' => ['CREATE TABLE "probe" ("id" integer not null, "label" varchar not null default \'it\'\'s autoincrement\', primary key ("id"))', false],
            'identifiers' => ['CREATE TABLE "autoincrement" (`autoincrement` integer primary key, [autoincrement] text, "a""autoincrement" text)', false],
            'comments' => ["CREATE TABLE probe (id integer primary key -- autoincrement\n, label text /* autoincrement */)", false],
        ];
    }

    #[DataProvider('tables')]
    public function test_it_finds_the_keyword(string $createTable, bool $expected): void
    {
        $this->assertSame($expected, SQLiteSchemaBuilder::autoIncrements($createTable));
    }
}
