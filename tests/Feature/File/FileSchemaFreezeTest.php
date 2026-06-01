<?php

namespace Tests\Feature\File;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the MySQL column shape that the upgrade tool's metadata-only file_bin
 * migration depends on. Asserted against information_schema actual values (not the
 * DDL string), since signedness and the PK-implied NOT NULL do not appear in a
 * CREATE TABLE the way the freeze requires checking. MySQL lane only — SQLite has
 * no signed/unsigned distinction and stores BLOB without a size class.
 */
class FileSchemaFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The assertions read information_schema and check MySQL signed/unsigned
        // and LONGBLOB — none of which exist on SQLite (the default test lane).
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('file_bin schema-freeze assertions are MySQL-specific (information_schema).');
        }
    }

    public function test_files_id_is_signed_int_primary_key(): void
    {
        $column = $this->column('files', 'id');

        $this->assertSame('int', $column['data_type']);
        $this->assertStringNotContainsStringIgnoringCase('unsigned', $column['column_type'], 'files.id must be a SIGNED int to match OpenPNE 3 file.id for the metadata-only FK rewire');
        $this->assertSame('PRI', $column['column_key']);
    }

    public function test_file_bin_is_frozen_to_its_four_columns(): void
    {
        $columns = collect(DB::select(
            'select column_name as name, data_type as data_type, column_type as column_type,
                    is_nullable as is_nullable, column_key as column_key
             from information_schema.columns
             where table_schema = ? and table_name = ?',
            [$this->schema(), 'file_bin'],
        ))->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['file_id', 'bin', 'created_at', 'updated_at'],
            $columns->keys()->all(),
            'file_bin must keep exactly the four OpenPNE 3 columns; adding/removing any breaks the metadata-only ALTER',
        );

        // file_id: signed INT PK, matching files.id.
        $this->assertSame('int', $columns['file_id']->data_type);
        $this->assertStringNotContainsStringIgnoringCase('unsigned', $columns['file_id']->column_type);
        $this->assertSame('PRI', $columns['file_id']->column_key);

        // bin: LONGBLOB (not BLOB), nullable as in OpenPNE 3.
        $this->assertSame('longblob', $columns['bin']->data_type);
        $this->assertSame('YES', $columns['bin']->is_nullable);

        // created_at / updated_at: DATETIME NOT NULL.
        $this->assertSame('datetime', $columns['created_at']->data_type);
        $this->assertSame('NO', $columns['created_at']->is_nullable);
        $this->assertSame('datetime', $columns['updated_at']->data_type);
        $this->assertSame('NO', $columns['updated_at']->is_nullable);
    }

    public function test_file_bin_foreign_key_cascades_to_files(): void
    {
        $constraint = DB::selectOne(
            'select rc.delete_rule as delete_rule, kcu.referenced_table_name as referenced_table
             from information_schema.referential_constraints rc
             join information_schema.key_column_usage kcu
               on kcu.constraint_schema = rc.constraint_schema
              and kcu.constraint_name = rc.constraint_name
             where rc.constraint_schema = ? and kcu.table_name = ?',
            [$this->schema(), 'file_bin'],
        );

        $this->assertNotNull($constraint, 'file_bin must have a foreign key');
        $this->assertSame('files', $constraint->referenced_table);
        $this->assertSame('CASCADE', $constraint->delete_rule);
    }

    private function schema(): string
    {
        return DB::connection()->getDatabaseName();
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $table, string $name): array
    {
        return (array) DB::selectOne(
            'select data_type as data_type, column_type as column_type,
                    is_nullable as is_nullable, column_key as column_key
             from information_schema.columns
             where table_schema = ? and table_name = ? and column_name = ?',
            [$this->schema(), $table, $name],
        );
    }
}
