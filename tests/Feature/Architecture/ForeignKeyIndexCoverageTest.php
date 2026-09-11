<?php

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every foreign key's columns lead some index; only SQLite can fail this, InnoDB indexes its own
 * (docs/internals/ordering.md, "SQLite foreign-key indexes"). RefreshDatabase is required: the SQLite
 * lane is in-memory, and an unmigrated schema has no foreign keys to fail on.
 */
class ForeignKeyIndexCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_foreign_key_column_leads_an_index(): void
    {
        $unindexed = [];
        $foreignKeys = 0;

        // MySQL's getTables() spans every user schema on the server; only the connected one is the subject.
        $schema = DB::connection()->getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null;
        foreach (Schema::getTables($schema) as $table) {
            $name = $table['name'];
            $indexes = array_map(fn (array $index) => $index['columns'], Schema::getIndexes($name));

            foreach (Schema::getForeignKeys($name) as $foreignKey) {
                $foreignKeys++;
                $columns = $foreignKey['columns'];
                $led = array_filter($indexes, fn (array $indexed) => array_slice($indexed, 0, count($columns)) === $columns);
                if ($led === []) {
                    $unindexed[] = $name.'.'.implode(',', $columns);
                }
            }
        }

        $this->assertGreaterThan(80, $foreignKeys, 'the schema under test has too few foreign keys to be the migrated one');
        $this->assertSame([], $unindexed);
    }
}
