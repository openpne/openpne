<?php

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every foreign key's columns lead some index, on whichever engine the test runs
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

        foreach (Schema::getTables() as $table) {
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

        $this->assertGreaterThan(30, $foreignKeys, 'the schema under test has too few foreign keys to be the migrated one');
        $this->assertSame([], $unindexed);
    }
}
