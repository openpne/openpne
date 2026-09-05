<?php

namespace Tests\Feature\Console;

use App\Files\DbBlobFileStorage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The copy runs from the app's own connection, so the mysql CI lane exercises the direction this
 * command exists for (MySQL → SQLite) while the sqlite lane covers SQLite → SQLite. The two tests
 * that need a hand-built schema stand up both sides themselves instead.
 */
class CopyDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> connection name, database file */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as [$connection, $path]) {
            DB::purge($connection);
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_it_copies_every_row_including_blob_bytes(): void
    {
        // Everything mysqldump would have to escape and SQLite would read differently.
        $name = "It's a \\test\nmember 日本語";
        $bytes = "\x89PNG\r\n\x1a\n\x00".random_bytes(4096);

        $member = Member::factory()->create(['name' => $name]);
        $file = File::factory()->create(['byte_size' => strlen($bytes)]);
        app(DbBlobFileStorage::class)->writeStream($file, $this->stream($bytes));

        $this->temporaryConnection('copy_target');

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])->assertSuccessful();

        $target = DB::connection('copy_target');

        $this->assertSame($name, $target->table('members')->where('id', $member->id)->value('name'));
        $this->assertSame(
            $bytes,
            (string) $target->table('file_bin')->where('file_id', $file->id)->value('bin'),
            'The BLOB must arrive byte-for-byte — the failure mysqldump --hex-blob produces silently.',
        );
    }

    public function test_the_copied_ids_do_not_collide_with_what_the_target_writes_next(): void
    {
        $member = Member::factory()->create();

        $this->temporaryConnection('copy_target');

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])->assertSuccessful();

        // Rows arrive with their source ids, so the target's auto-increment has to resume above them
        // rather than from wherever an empty table would have started.
        $next = DB::connection('copy_target')->table('members')->insertGetId([
            'name' => 'after the copy',
            'email' => 'after-the-copy@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertGreaterThan($member->id, $next);
    }

    public function test_it_refuses_a_target_that_already_holds_rows(): void
    {
        Member::factory()->create();

        $this->temporaryConnection('copy_target');

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])->assertSuccessful();

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])
            ->expectsOutputToContain('already holds rows')
            ->assertFailed();
    }

    public function test_it_refuses_to_copy_a_database_onto_itself(): void
    {
        $this->artisan('openpne:copy-database', ['--to' => config('database.default')])
            ->expectsOutputToContain('resolve to the same database')
            ->assertFailed();
    }

    public function test_it_reports_source_tables_the_schema_does_not_define_and_copies_the_rest(): void
    {
        // What an OpenPNE 3 upgrade leaves behind in the same database.
        $source = $this->handBuiltSchema('copy_source', ['member' => 'id integer primary key, nickname text']);
        $target = $this->handBuiltSchema('copy_target');

        $source->table('things')->insert(['id' => 1, 'label' => 'kept']);
        $source->table('member')->insert(['id' => 1, 'nickname' => 'left behind']);

        $this->artisan('openpne:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target'])
            ->expectsOutputToContain('exist only on [copy_source]')
            ->expectsOutputToContain('member')
            ->assertSuccessful();

        $this->assertSame('kept', $target->table('things')->where('id', 1)->value('label'));
    }

    public function test_it_refuses_a_target_table_the_source_does_not_have(): void
    {
        $this->handBuiltSchema('copy_source');
        $target = $this->handBuiltSchema('copy_target', ['custom_audit' => 'id integer primary key, note text']);
        $target->table('custom_audit')->insert(['id' => 1, 'note' => 'not from the source']);

        // A table the copy never writes: an emptiness check restricted to the copied tables would
        // pass here.
        $this->artisan('openpne:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target'])
            ->expectsOutputToContain('does not have: custom_audit')
            ->assertFailed();

        $this->assertSame(1, $target->table('custom_audit')->count(), 'It must refuse before touching the target.');
    }

    public function test_it_refuses_a_column_only_one_side_has(): void
    {
        $this->handBuiltSchema('copy_source', ['things' => 'id integer primary key, label text, custom text']);
        $this->handBuiltSchema('copy_target');

        $this->artisan('openpne:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target'])
            ->expectsOutputToContain('only on copy_source: custom')
            ->assertFailed();

        $this->handBuiltSchema('copy_source2');
        $this->handBuiltSchema('copy_target2', ['things' => 'id integer primary key, label text, extra text']);

        $this->artisan('openpne:copy-database', ['--from' => 'copy_source2', '--to' => 'copy_target2'])
            ->expectsOutputToContain('only on copy_target2: extra')
            ->assertFailed();
    }

    /**
     * Both sides must agree on `migrations`, so the fixture always holds it.
     *
     * @param  array<string, string>  $tables  name => column DDL
     */
    private function handBuiltSchema(string $connection, array $tables = []): Connection
    {
        $this->temporaryConnection($connection);
        $database = DB::connection($connection);

        $database->statement('create table migrations (id integer primary key, migration text, batch integer)');
        $database->table('migrations')->insert(['id' => 1, 'migration' => '0001_01_01_000000_create_things', 'batch' => 1]);

        foreach (['things' => 'id integer primary key, label text', ...$tables] as $table => $columns) {
            $database->statement("create table {$table} ({$columns})");
        }

        return $database;
    }

    /** The scratch file is removed in tearDown. */
    private function temporaryConnection(string $name): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openpne-copy-');
        $this->temporary[] = [$name, $path];

        config(["database.connections.{$name}" => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::purge($name);
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
