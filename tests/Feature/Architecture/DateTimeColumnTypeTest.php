<?php

namespace Tests\Feature\Architecture;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Times are stored as DATETIME (docs/internals/runtime.md, "Site timezone"). SQLite types both as
 * datetime, so only the MySQL lane can fail the schema test.
 */
class DateTimeColumnTypeTest extends TestCase
{
    use RefreshDatabase;

    private const CONVERTER = '2026_11_18_000001_convert_timestamp_columns_to_datetime';

    /** A migration sorted before the converter runs after it on an installed site and keeps its TIMESTAMP. */
    private const MIGRATIONS_BEFORE_THE_CONVERTER = 109;

    public function test_no_column_is_a_timestamp(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no TIMESTAMP type to tell apart.');
        }

        $timestamps = [];
        $datetimes = 0;

        foreach (Schema::getTables(DB::connection()->getDatabaseName()) as $table) {
            foreach (Schema::getColumns($table['name']) as $column) {
                match ($column['type_name']) {
                    'timestamp' => $timestamps[] = $table['name'].'.'.$column['name'],
                    'datetime' => $datetimes++,
                    default => null,
                };
            }
        }

        $this->assertGreaterThan(100, $datetimes, 'the schema under test has too few time columns to be the migrated one');
        $this->assertSame([], $timestamps);
    }

    public function test_no_migration_is_sorted_before_the_converter(): void
    {
        $names = array_map(fn (string $path) => basename($path, '.php'), glob(database_path('migrations/*.php')));
        sort($names);

        $this->assertSame(self::MIGRATIONS_BEFORE_THE_CONVERTER, array_search(self::CONVERTER, $names, true), 'name a new migration after '.self::CONVERTER);
    }

    public function test_every_table_the_converter_names_has_a_datetime_column(): void
    {
        foreach ($this->converter()::TABLES as $table) {
            $this->assertContains('datetime', array_column(Schema::getColumns($table), 'type_name'), $table);
        }
    }

    public function test_the_converter_keeps_the_database_clock_default_on_exactly_the_columns_a_fresh_install_has_it_on(): void
    {
        $defaults = [];

        foreach ($this->converter()::TABLES as $table) {
            foreach (Schema::getColumns($table) as $column) {
                // MariaDB reports the default as current_timestamp().
                if (in_array(strtolower((string) $column['default']), ['current_timestamp', 'current_timestamp()'], true)) {
                    $defaults[] = $table.'.'.$column['name'];
                }
            }
        }

        $this->assertEqualsCanonicalizing($this->converter()::DATABASE_CLOCK_DEFAULTS, $defaults);
    }

    private function converter(): Migration
    {
        return require database_path('migrations/'.self::CONVERTER.'.php');
    }
}
