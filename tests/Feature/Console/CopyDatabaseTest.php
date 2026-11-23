<?php

namespace Tests\Feature\Console;

use App\Files\DbBlobFileStorage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\FakesDatabaseServer;
use Tests\TestCase;

/**
 * The copy runs from the app's own connection, so the mysql CI lane exercises the direction this
 * command exists for (MySQL → SQLite) while the sqlite lane covers SQLite → SQLite. The two tests
 * that need a hand-built schema stand up both sides themselves instead.
 */
class CopyDatabaseTest extends TestCase
{
    use FakesDatabaseServer;
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> connection name, database file */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mariadb' && $this->name() !== 'test_a_mariadb_site_is_refused_as_the_source') {
            $this->markTestSkipped('The command does not copy MariaDB yet.');
        }
    }

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
        $this->assertGreaterThan($member->id, $this->insertMember('copy_target'));
    }

    /** @return array<string, array{bool}> */
    public static function deletions(): array
    {
        return ['the latest row deleted' => [false], 'every row deleted' => [true]];
    }

    #[DataProvider('deletions')]
    public function test_the_target_does_not_hand_out_a_deleted_rows_id(bool $everyRow): void
    {
        $kept = Member::factory()->create();
        $deleted = Member::factory()->create();
        DB::table('members')->whereIn('id', $everyRow ? [$kept->id, $deleted->id] : [$deleted->id])->delete();

        $this->temporaryConnection('copy_target');

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])->assertSuccessful();

        $this->assertGreaterThan($deleted->id, $this->insertMember('copy_target'));
    }

    #[TestWith(['pgsql', '[copy_target] uses the [pgsql] driver; only SQLite and MySQL are supported.'])]
    #[TestWith(['mariadb', '[copy_target] uses the [mariadb] driver, which this command does not copy to or from yet.'])]
    public function test_it_refuses_a_driver_other_than_sqlite_and_mysql_before_touching_it(string $driver, string $refusal): void
    {
        config(['database.connections.copy_target' => ['driver' => $driver, 'host' => '203.0.113.1', 'database' => 'openpne']]);

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])
            ->expectsOutputToContain($refusal)
            ->assertFailed();
    }

    public function test_it_refuses_a_mariadb_server_on_either_side_before_writing(): void
    {
        $this->temporaryConnection('copy_target');
        $this->fakeDatabaseServer('10.11.19-MariaDB-ubu2204', 'copy_target');

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])
            ->expectsOutputToContain('[copy_target] is MariaDB 10.11.19, which this command does not copy to or from yet.')
            ->assertFailed();
        $this->assertFalse(DB::connection('copy_target')->getSchemaBuilder()->hasTable('migrations'));

        $from = (string) config('database.default');
        $this->fakeDatabaseServer('11.8.9-MariaDB-ubu2404', $from);

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])
            ->expectsOutputToContain("[{$from}] is MariaDB 11.8.9, which this command does not copy to or from yet.")
            ->assertFailed();
        $this->assertFalse(DB::connection('copy_target')->getSchemaBuilder()->hasTable('migrations'));
    }

    public function test_a_mariadb_site_is_refused_as_the_source(): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs the MariaDB test connection.');
        }

        $this->temporaryConnection('copy_target');
        $from = (string) config('database.default');

        $this->artisan('openpne:copy-database', ['--to' => 'copy_target'])
            ->expectsOutputToContain("[{$from}] uses the [mariadb] driver, which this command does not copy to or from yet.")
            ->assertFailed();
        $this->assertFalse(DB::connection('copy_target')->getSchemaBuilder()->hasTable('migrations'));
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

    private function insertMember(string $connection): int
    {
        return (int) DB::connection($connection)->table('members')->insertGetId([
            'name' => 'after the copy',
            'email' => 'after-the-copy@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
