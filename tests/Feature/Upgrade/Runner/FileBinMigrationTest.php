<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\File;
use App\Models\UpgradeState;
use App\Upgrade\Column;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\FileBinMigration;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\RunReport;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\TestCase;

/**
 * file_bin migration: the FK rewire (file→files), the cross-table/database move, the bytes-complete
 * preflight, and reset() not cascade-deleting the preserved BLOBs. MySQL-only, like the runner.
 */
class FileBinMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const BLOB = "\x00\x01\x02\xff\xfeGIF89a binary\x00payload\xed";

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('file_bin migration is a MySQL ALTER/RENAME over information_schema.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->cleanup();
        }

        parent::tearDown();
    }

    public function test_rewire_repoints_the_fk_onto_files_keeping_the_bytes(): void
    {
        $ids = $this->seedInPlace();

        (new FileBinMigration)->rewire($this->out($lines));

        $this->assertSame('files', $this->fkReferencedTable('file_bin'));
        $this->assertStringContainsString('DONE file_bin_rewire', implode("\n", $lines));
        $this->assertSame(count($ids), (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`'));
        $this->assertSame(self::BLOB, DB::table('file_bin')->where('file_id', $ids[0])->value('bin'));
    }

    public function test_rewire_is_idempotent(): void
    {
        $this->seedInPlace();
        $migration = new FileBinMigration;

        $migration->rewire($this->out($first));
        $migration->rewire($this->out($second));

        $this->assertStringContainsString('DONE file_bin_rewire', implode("\n", $first));
        $this->assertStringContainsString('SKIP file_bin_rewire', implode("\n", $second));
        $this->assertSame('files', $this->fkReferencedTable('file_bin'));
    }

    public function test_rewire_no_ops_a_fresh_install_file_bin(): void
    {
        // The migration-created file_bin already references files; nothing to do.
        File::factory()->create();

        (new FileBinMigration)->rewire($this->out($lines));

        $this->assertStringContainsString('SKIP file_bin_rewire', implode("\n", $lines));
        $this->assertSame('files', $this->fkReferencedTable('file_bin'));
    }

    public function test_snapshot_records_the_max_file_id_respecting_the_prefix(): void
    {
        $this->createOp3File('`op_file`');
        $this->seedFile('`op_file`', 7, 'a');
        $this->seedFile('`op_file`', 42, 'b');

        (new FileBinMigration)->snapshot('op_', null, $this->out($lines));

        $this->assertDatabaseHas('openpne4_upgrade_state', [
            'step_key' => 'file_id_max_snapshot',
            'status' => UpgradeState::STATUS_COMPLETED,
        ]);
        $this->assertSame(42, UpgradeState::where('step_key', 'file_id_max_snapshot')->value('metadata')['max_file_id']);
        $this->assertStringContainsString('max file.id = 42', implode("\n", $lines));
    }

    public function test_reset_does_not_cascade_delete_the_bytes(): void
    {
        $ids = $this->seedInPlace();
        (new FileBinMigration)->rewire($this->out($ignore)); // file_bin FK → files ON DELETE CASCADE

        // A files-targeting run's reset() must drop the FK and clear files without losing the BLOBs.
        (new UpgradeRunner(new InsertSelectCompiler, [$this->fileStep()]))->reset();

        $this->assertDatabaseCount('files', 0);
        $this->assertSame(count($ids), (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`'));
        $this->assertSame(self::BLOB, DB::table('file_bin')->where('file_id', $ids[0])->value('bin'));
        $this->assertNull($this->fkReferencedTable('file_bin'), 'the FK is dropped so the delete cannot cascade');
    }

    public function test_force_restart_reruns_snapshot_and_rewire_keeping_the_bytes(): void
    {
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        $this->seedFile('`file`', 1, 'a');
        $this->seedFile('`file`', 2, 'b');
        $this->seedFileBin('`file_bin`', 1, self::BLOB);
        $this->seedFileBin('`file_bin`', 2, 'second');

        $runner = new UpgradeRunner(new InsertSelectCompiler, [$this->fileStep()]);

        $this->assertTrue($runner->run(new RunOptions, null, $this->out($ignore)));
        $this->assertDatabaseCount('files', 2);
        $this->assertSame('files', $this->fkReferencedTable('file_bin'));

        $this->assertTrue($runner->run(new RunOptions(forceRestart: true), null, $this->out($ignore)));
        $this->assertDatabaseCount('files', 2);
        $this->assertSame('files', $this->fkReferencedTable('file_bin'));
        $this->assertSame(self::BLOB, DB::table('file_bin')->where('file_id', 1)->value('bin'));
        $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => 'file_bin_rewire', 'status' => UpgradeState::STATUS_COMPLETED]);
        $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => 'file_id_max_snapshot', 'status' => UpgradeState::STATUS_COMPLETED]);
    }

    public function test_move_renames_a_separate_database_file_bin_into_the_app(): void
    {
        $ids = $this->seedSeparateDatabase();
        $migration = new FileBinMigration;

        $migration->move('', $this->sourceDb(), $this->out($lines));
        $migration->rewire($this->out($ignore));

        $this->assertStringContainsString('DONE file_bin_move', implode("\n", $lines));
        $this->assertFalse($this->tableExists($this->sourceDb(), 'file_bin'), 'the source file_bin is moved out');
        $this->assertSame(count($ids), (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`'));
        $this->assertSame(self::BLOB, DB::table('file_bin')->where('file_id', $ids[0])->value('bin'));
        $this->assertSame('files', $this->fkReferencedTable('file_bin'));

        // Idempotent: a second move finds the source gone and leaves the app BLOBs untouched.
        $migration->move('', $this->sourceDb(), $this->out($second));
        $this->assertStringContainsString('SKIP file_bin_move', implode("\n", $second));
        $this->assertSame(count($ids), (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`'));
    }

    public function test_preflight_and_move_accept_a_hyphenated_source_database(): void
    {
        $db = $this->hyphenatedSourceDb();
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$db.'`');
        $this->createOp3File("`{$db}`.`file`");
        $this->createOp3FileBin("`{$db}`.`file_bin`", "`{$db}`.`file`", 'hyphen_file_bin_fk');
        $this->seedFile("`{$db}`.`file`", 1, 'a');
        $this->seedFileBin("`{$db}`.`file_bin`", 1, self::BLOB);
        $migration = new FileBinMigration;

        $this->assertNull($migration->preflight('', $db));

        $migration->move('', $db, $this->out($lines));

        $this->assertStringContainsString('DONE file_bin_move', implode("\n", $lines));
        $this->assertSame(self::BLOB, DB::table('file_bin')->where('file_id', 1)->value('bin'));
    }

    public function test_move_renames_a_prefixed_same_database_file_bin(): void
    {
        // A --source-prefix run: the bytes live in `op_file_bin`, so no-oping on the empty app
        // file_bin would silently leave the BLOBs behind.
        $ids = $this->seedPrefixed();

        (new FileBinMigration)->move('op_', null, $this->out($lines));
        (new FileBinMigration)->rewire($this->out($ignore));

        $this->assertStringContainsString('DONE file_bin_move', implode("\n", $lines));
        $this->assertFalse($this->tableExists($this->database(), 'op_file_bin'), 'op_file_bin is renamed away');
        $this->assertSame(count($ids), (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`'));
        $this->assertSame(self::BLOB, DB::table('file_bin')->where('file_id', $ids[0])->value('bin'));
        $this->assertSame('files', $this->fkReferencedTable('file_bin'));
    }

    public function test_preflight_aborts_when_bytes_are_incomplete(): void
    {
        // file has 2 rows but file_bin only 1 — an incomplete dump would migrate a file without its bytes.
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        $this->seedFile('`file`', 1, 'a');
        $this->seedFile('`file`', 2, 'b');
        $this->seedFileBin('`file_bin`', 1, self::BLOB);

        $error = (new FileBinMigration)->preflight('', null);

        $this->assertNotNull($error);
        $this->assertStringContainsString('file has 2 rows but file_bin has 1', $error);
    }

    public function test_preflight_names_the_file_rows_without_bytes(): void
    {
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        foreach (range(1, 9) as $id) {
            $this->seedFile('`file`', $id, "f{$id}");
        }
        $this->seedFileBin('`file_bin`', 2, self::BLOB);
        $this->seedFileBin('`file_bin`', 5, self::BLOB);

        $error = (new FileBinMigration)->preflight('', null);

        $this->assertStringContainsString('file has 9 rows but file_bin has 2', $error);
        $this->assertStringContainsString('7 `file` row(s) have no `file_bin` row (id 1, 3, 4, 6, 7 and 2 more).', $error);
        $this->assertStringNotContainsString('have no `file` row', $error);
        $this->assertStringContainsString('Restore a complete dump', $error);
    }

    public function test_preflight_names_the_bytes_without_a_file_row_on_a_prefixed_source(): void
    {
        $this->createOp3File('`op_file`');
        $this->createOp3FileBin('`op_file_bin`', '`op_file`', 'op_file_bin_fk');
        $this->seedFile('`op_file`', 1, 'a');
        $this->seedFileBin('`op_file_bin`', 1, self::BLOB);
        // A dump restored with FOREIGN_KEY_CHECKS=0 (mysqldump's default) keeps bytes for a deleted file row.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->seedFileBin('`op_file_bin`', 7, 'stray');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $error = (new FileBinMigration)->preflight('op_', null);

        $this->assertStringContainsString('file has 1 rows but file_bin has 2', $error);
        $this->assertStringContainsString('1 `op_file_bin` row(s) have no `op_file` row (file_id 7).', $error);
        $this->assertStringNotContainsString('have no `op_file_bin` row', $error);
    }

    public function test_preflight_aborts_on_equal_counts_that_do_not_pair_up(): void
    {
        // One file without bytes and one stray file_bin row: the counts agree, but rewire() would fail
        // its FK after the walk had already written files.
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        $this->seedFile('`file`', 1, 'a');
        $this->seedFile('`file`', 2, 'b');
        $this->seedFileBin('`file_bin`', 1, self::BLOB);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->seedFileBin('`file_bin`', 3, 'stray');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $error = (new FileBinMigration)->preflight('', null);

        $this->assertNotNull($error);
        $this->assertStringContainsString('file and file_bin both have 2 rows but they do not pair up', $error);
        $this->assertStringContainsString('1 `file` row(s) have no `file_bin` row (id 2).', $error);
        $this->assertStringContainsString('1 `file_bin` row(s) have no `file` row (file_id 3).', $error);
    }

    public function test_preflight_after_the_move_points_at_the_app_file_bin(): void
    {
        // A resumed --source-prefix run: op_file_bin was already renamed to file_bin, so the pairing is checked against the app table.
        $this->seedPrefixed();
        (new FileBinMigration)->move('op_', null, $this->out($ignore));
        $this->seedFile('`op_file`', 99, 'late');

        $error = (new FileBinMigration)->preflight('op_', null);

        $this->assertStringContainsString('file has 3 rows but file_bin has 2', $error);
        $this->assertStringContainsString('1 `op_file` row(s) have no `file_bin` row (id 99).', $error);
        $this->assertStringContainsString('already moved into this database', $error);
        $this->assertStringNotContainsString('Restore a complete dump', $error);
    }

    public function test_preflight_without_any_file_bin_table_says_so(): void
    {
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->seedFile('`file`', 1, 'a');

        $error = (new FileBinMigration)->preflight('', null);

        $this->assertStringContainsString('file has 1 rows but file_bin has 0', $error);
        $this->assertStringContainsString('No file_bin table holds the bytes', $error);
        $this->assertStringNotContainsString('already moved', $error);
    }

    public function test_preflight_aborts_before_the_walk_writing_nothing(): void
    {
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        $this->seedFile('`file`', 1, 'a');
        $this->seedFile('`file`', 2, 'b');
        $this->seedFileBin('`file_bin`', 1, self::BLOB);

        $ok = (new UpgradeRunner(new InsertSelectCompiler, [$this->fileStep()]))->run(new RunOptions, null, $this->out($lines));

        $this->assertFalse($ok);
        $this->assertStringContainsString('file has 2 rows but file_bin has 1', implode("\n", $lines));
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_preflight_refuses_to_drop_a_non_empty_app_file_bin(): void
    {
        $ids = $this->seedPrefixed();
        // An unexpected row in the app file_bin (which move would DROP): the preflight must refuse.
        $this->seedFileBin('`file_bin`', $ids[0], 'unexpected');

        $error = (new FileBinMigration)->preflight('op_', null);

        $this->assertNotNull($error);
        $this->assertStringContainsString('app file_bin already has rows', $error);
        $this->assertSame(1, (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`'), 'the app file_bin is untouched');
    }

    public function test_a_failing_rewire_is_one_fail_line_with_a_failed_checkpoint(): void
    {
        // Called directly because run()'s preflight refuses bytes with no file row before the FK add.
        $this->seedInPlace();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->seedFileBin('`file_bin`', 999, 'orphan');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $ok = (new FileBinMigration)->rewire($this->out($lines));

        $this->assertFalse($ok);
        $fails = array_values(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'FAIL')));
        $this->assertCount(1, $fails);
        $this->assertStringStartsWith('FAIL file_bin_rewire: SQLSTATE[23000]', $fails[0]);
        $this->assertStringNotContainsString("\n", $fails[0]);
        $this->assertStringNotContainsString('ALTER TABLE', $fails[0]);
        $state = UpgradeState::query()->where('step_key', 'file_bin_rewire')->first();
        $this->assertSame(UpgradeState::STATUS_FAILED, $state->status);
        $this->assertStringStartsWith('SQLSTATE[23000]', $state->error);

        DB::table('file_bin')->where('file_id', 999)->delete();

        $this->assertTrue((new FileBinMigration)->rewire($this->out($again)));
        $this->assertStringContainsString('DONE file_bin_rewire', implode("\n", $again));
        $this->assertNull(UpgradeState::query()->where('step_key', 'file_bin_rewire')->value('error'));
    }

    public function test_a_failing_move_is_one_fail_line_and_resumes_after_the_cause_is_removed(): void
    {
        $ids = $this->seedSeparateDatabase();
        // The refusal the preflight normally stops; called directly it surfaces as the step failure.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->seedFileBin('`file_bin`', $ids[0], 'unexpected');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $migration = new FileBinMigration;

        $ok = $migration->move('', $this->sourceDb(), $this->out($lines));

        $this->assertFalse($ok);
        $this->assertContains('FAIL file_bin_move: refusing to drop a non-empty app file_bin', $lines);
        $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => 'file_bin_move', 'status' => UpgradeState::STATUS_FAILED]);
        $this->assertTrue($this->tableExists($this->sourceDb(), 'file_bin'), 'the source bytes are still where they were');

        DB::table('file_bin')->delete();

        $this->assertTrue($migration->move('', $this->sourceDb(), $this->out($again)));
        $this->assertStringContainsString('DONE file_bin_move', implode("\n", $again));
        $this->assertSame(count($ids), (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`'));
    }

    public function test_the_next_steps_belong_to_the_run_that_first_completed_the_site(): void
    {
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        $this->seedFile('`file`', 1, 'a');
        $this->seedFileBin('`file_bin`', 1, self::BLOB);
        $runner = new UpgradeRunner(new InsertSelectCompiler, [$this->fileStep()]);

        $first = $this->out($lines);
        $this->assertTrue($runner->run(new RunOptions, null, $first));
        $this->assertStringContainsString('Each migrated picture', implode("\n", $lines));
        $this->assertStringContainsString('RUN file_id_max_snapshot', implode("\n", $lines));
        $this->assertCount(2, $first->jsonSerialize()['next_steps']);

        // A completed site runs nothing: no next steps, nothing resumed.
        $second = $this->out($again);
        $this->assertTrue($runner->run(new RunOptions, null, $second));
        $this->assertStringNotContainsString('Each migrated picture', implode("\n", $again));
        $this->assertStringContainsString('Completed:', implode("\n", $again));
        $this->assertSame([], $second->jsonSerialize()['next_steps']);
        $this->assertFalse($second->jsonSerialize()['resumed']);
    }

    public function test_the_create_migration_skips_a_preexisting_file_bin(): void
    {
        // Simulate the same-database upgrade: an OpenPNE 3 file_bin already present when migrate reruns.
        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        $this->seedFile('`file`', 1, 'a');
        $this->seedFileBin('`file_bin`', 1, self::BLOB);

        (require database_path('migrations/2026_06_01_000100_create_file_bin_table.php'))->up();

        // The guard returned early: the OpenPNE 3 file_bin (FK → file, bytes intact) is untouched.
        $this->assertSame('file', $this->fkReferencedTable('file_bin'));
        $this->assertSame(self::BLOB, DB::table('file_bin')->where('file_id', 1)->value('bin'));
    }

    private function fileStep(): UpgradeStep
    {
        return new class extends UpgradeStep
        {
            protected string $source = 'file';

            protected string $target = 'files';

            public function columns(): array
            {
                return [
                    'id' => Column::source('id'),
                    'name' => Column::source('name'),
                    'type' => Column::source('type'),
                    'byte_size' => Column::source('filesize'),
                    'created_at' => Column::source('created_at'),
                    'updated_at' => Column::source('updated_at'),
                ];
            }
        };
    }

    /** @return list<int> the file ids, matching the seeded `files` rows */
    private function seedInPlace(): array
    {
        $ids = File::factory()->count(2)->create()->pluck('id')->all();

        $this->dropAppFileBin();
        $this->createOp3File('`file`');
        $this->createOp3FileBin('`file_bin`', '`file`', 'file_bin_file_id_file_id');
        foreach ($ids as $id) {
            $this->seedFile('`file`', $id, "f{$id}");
            $this->seedFileBin('`file_bin`', $id, self::BLOB);
        }

        return $ids;
    }

    /** @return list<int> */
    private function seedPrefixed(): array
    {
        $ids = File::factory()->count(2)->create()->pluck('id')->all();

        $this->createOp3File('`op_file`');
        $this->createOp3FileBin('`op_file_bin`', '`op_file`', 'op_file_bin_fk');
        foreach ($ids as $id) {
            $this->seedFile('`op_file`', $id, "f{$id}");
            $this->seedFileBin('`op_file_bin`', $id, self::BLOB);
        }

        return $ids;
    }

    /** @return list<int> */
    private function seedSeparateDatabase(): array
    {
        $ids = File::factory()->count(2)->create()->pluck('id')->all();

        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$this->sourceDb().'`');
        $src = '`'.$this->sourceDb().'`';
        $this->createOp3File("{$src}.`file`");
        $this->createOp3FileBin("{$src}.`file_bin`", "{$src}.`file`", 'src_file_bin_fk');
        foreach ($ids as $id) {
            $this->seedFile("{$src}.`file`", $id, "f{$id}");
            $this->seedFileBin("{$src}.`file_bin`", $id, self::BLOB);
        }

        return $ids;
    }

    private function createOp3File(string $qualified): void
    {
        DB::statement("CREATE TABLE {$qualified} (
            `id` int NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL DEFAULT '',
            `type` varchar(64) NOT NULL DEFAULT '',
            `filesize` int NOT NULL DEFAULT 0,
            `original_filename` text,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB");
    }

    private function createOp3FileBin(string $qualified, string $fileRef, string $constraint): void
    {
        DB::statement("CREATE TABLE {$qualified} (
            `file_id` int NOT NULL,
            `bin` longblob,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`file_id`),
            CONSTRAINT `{$constraint}` FOREIGN KEY (`file_id`) REFERENCES {$fileRef} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    private function seedFile(string $table, int $id, string $name): void
    {
        DB::statement("INSERT INTO {$table} (`id`, `name`, `type`, `filesize`, `created_at`, `updated_at`) VALUES (?, ?, 'image/png', 10, '2018-01-02 03:04:05', '2018-01-02 03:04:05')", [$id, $name]);
    }

    private function seedFileBin(string $table, int $fileId, string $blob): void
    {
        DB::statement("INSERT INTO {$table} (`file_id`, `bin`, `created_at`, `updated_at`) VALUES (?, ?, '2018-01-02 03:04:05', '2018-01-02 03:04:05')", [$fileId, $blob]);
    }

    private function dropAppFileBin(): void
    {
        DB::statement('DROP TABLE IF EXISTS `file_bin`');
    }

    private function fkReferencedTable(string $table): ?string
    {
        $row = DB::selectOne(
            'select kcu.referenced_table_name as referenced_table
               from information_schema.referential_constraints rc
               join information_schema.key_column_usage kcu
                 on kcu.constraint_schema = rc.constraint_schema and kcu.constraint_name = rc.constraint_name
              where rc.constraint_schema = ? and kcu.table_name = ? and kcu.column_name = ?',
            [$this->database(), $table, 'file_id'],
        );

        return $row?->referenced_table;
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ? limit 1',
            [$schema, $table],
        ) !== null;
    }

    private function database(): string
    {
        return DB::connection()->getDatabaseName();
    }

    // Per-worker source database: --functional spreads a class's methods across paratest workers, so a
    // shared fixed name would let concurrent methods create/drop each other's source DB (a real race).
    private function sourceDb(): string
    {
        $token = ParallelTesting::token();

        return 'op3_filebin_src'.($token ? '_'.$token : '');
    }

    private function hyphenatedSourceDb(): string
    {
        return str_replace('_', '-', $this->sourceDb()).'-hyphen';
    }

    /** @param  mixed  $lines  populated with the captured output lines */
    private function out(&$lines): RunReport
    {
        $lines = [];

        return new RunReport(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
    }

    private function cleanup(): void
    {
        // Only the manually-created source tables; `file_bin` is a migration table DatabaseMigrations
        // recreates each test (the fresh-install case relies on that pristine one).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['`op_file_bin`', '`file`', '`op_file`'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::statement('DROP DATABASE IF EXISTS `'.$this->sourceDb().'`');
        DB::statement('DROP DATABASE IF EXISTS `'.$this->hyphenatedSourceDb().'`');
    }
}
