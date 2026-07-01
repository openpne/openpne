<?php

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Migrates the OpenPNE 3 `file_bin` BLOBs (gigabytes/site) into OpenPNE 4 without rewriting a byte:
 *
 *  - snapshot:  record MAX(source file.id) for a post-switchover rollback bound.
 *  - move:      when the source file_bin is a different physical table (a --source-prefix or
 *               --source-database run), RENAME it onto the app's `file_bin` (.ibd move, no row copy).
 *               In-place (same database, no prefix) needs no move — the dump's file_bin already IS
 *               the app's, kept by the create_file_bin_table guard.
 *  - rewire:    re-point file_bin.file_id's FK from the old `file` table onto `files`. FileUpgrade
 *               copies file→files preserving ids, so the bytes stay addressable.
 *
 * MySQL-only (like the runner), driven by the runner's output closure. Idempotency comes from
 * information_schema state (the FK's referenced table, the source table's presence), so a resume or
 * --force-restart re-runs cleanly. Assumes DB-blob storage: OpenPNE 3's default file storage writes a
 * file_bin row per file (1:1), so a file/file_bin count mismatch is an incomplete or filesystem-storage
 * dump and is rejected by preflight() rather than migrating file metadata without its bytes.
 */
final class FileBinMigration
{
    private const SNAPSHOT_KEY = 'file_id_max_snapshot';

    private const MOVE_KEY = 'file_bin_move';

    private const REWIRE_KEY = 'file_bin_rewire';

    /**
     * Pre-walk, read-only. Returns an error to abort the run (before any write), or null. Call only
     * once SourcePreflight has passed, so the source `file` table is present to COUNT.
     */
    public function preflight(string $sourcePrefix, ?string $sourceDatabase): ?string
    {
        $fileRows = (int) DB::scalar('SELECT COUNT(*) FROM '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file'));

        // Where the bytes live now: the source file_bin (a fresh run), else the app's own (in-place, or
        // a prior run already moved them). An absent app file_bin (a crash mid-move) counts as 0.
        $sourcePresent = $this->tableExists($this->sourceSchema($sourceDatabase), $sourcePrefix.'file_bin');
        $bytesRows = $sourcePresent
            ? (int) DB::scalar('SELECT COUNT(*) FROM '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file_bin'))
            : $this->appFileBinCount();

        if ($fileRows !== $bytesRows) {
            return "file has {$fileRows} rows but file_bin has {$bytesRows} — the OpenPNE 3 dump is incomplete, or uses filesystem file storage (unsupported); every file's bytes must be a file_bin row (DB-blob storage).";
        }

        if (! $this->inPlace($sourcePrefix, $sourceDatabase) && $sourcePresent && $this->appFileBinCount() > 0) {
            return 'the app file_bin already has rows — refusing to drop it to move the source file_bin in; --force-restart from a clean state instead.';
        }

        return null;
    }

    public function snapshot(string $sourcePrefix, ?string $sourceDatabase, Closure $out): void
    {
        $max = DB::scalar('SELECT MAX(`id`) FROM '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file'));

        UpgradeState::updateOrCreate(
            ['step_key' => self::SNAPSHOT_KEY],
            ['status' => UpgradeState::STATUS_COMPLETED, 'metadata' => ['max_file_id' => $max === null ? null : (int) $max]],
        );
        $out('SNAPSHOT '.self::SNAPSHOT_KEY.': max file.id = '.($max ?? 'none'));
    }

    /**
     * Move the source file_bin onto the app's `file_bin` for a --source-prefix / --source-database run.
     * Idempotent across a mid-move crash: an already-moved source (absent) skips; the app drop is
     * skipped when the app file_bin is absent (a prior run dropped it) so the rename still heals.
     */
    public function move(string $sourcePrefix, ?string $sourceDatabase, Closure $out): void
    {
        if ($this->inPlace($sourcePrefix, $sourceDatabase)) {
            return;
        }

        $sourceSchema = $this->sourceSchema($sourceDatabase);
        $sourceTable = $sourcePrefix.'file_bin';
        $sourcePhysical = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file_bin');

        if (! $this->tableExists($sourceSchema, $sourceTable)) {
            $out('SKIP '.self::MOVE_KEY.': source file_bin already moved');
            $this->checkpoint(self::MOVE_KEY);

            return;
        }

        // Drop the source's own FK first (it references the source `file` — `op_file` under a prefix)
        // so the rename carries no cross-table constraint; rewire() adds the OpenPNE 4 FK afterwards.
        $sourceFk = $this->foreignKey($sourceSchema, $sourceTable);
        if ($sourceFk !== null) {
            DB::statement("ALTER TABLE {$sourcePhysical} DROP FOREIGN KEY `{$sourceFk->constraint_name}`");
        }

        if ($this->appFileBinExists()) {
            if ($this->appFileBinCount() > 0) {
                throw new RuntimeException('refusing to drop a non-empty app file_bin'); // preflight already rejects this
            }
            DB::statement('DROP TABLE `file_bin`');
        }

        DB::statement("RENAME TABLE {$sourcePhysical} TO `file_bin`");
        $this->checkpoint(self::MOVE_KEY);
        $out('DONE '.self::MOVE_KEY.": moved {$sourcePhysical} into file_bin");
    }

    /** Re-point the app file_bin's file_id FK onto `files`. 3-way idempotent by the referenced table. */
    public function rewire(Closure $out): void
    {
        $fk = $this->foreignKey($this->database(), 'file_bin');

        if ($fk !== null && $fk->referenced_table === 'files') {
            $out('SKIP '.self::REWIRE_KEY.': file_id already references files');
            $this->checkpoint(self::REWIRE_KEY);

            return;
        }

        if ($fk !== null) {
            DB::statement("ALTER TABLE `file_bin` DROP FOREIGN KEY `{$fk->constraint_name}`");
        }
        DB::statement('ALTER TABLE `file_bin` ADD CONSTRAINT `file_bin_file_id_foreign` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE');
        $this->checkpoint(self::REWIRE_KEY);
        $out('DONE '.self::REWIRE_KEY.': file_id now references files');
    }

    /** For reset(): drop the app file_bin's FK so DELETEing `files` cannot cascade into the BLOBs. */
    public function dropForeignKey(): void
    {
        $fk = $this->foreignKey($this->database(), 'file_bin');
        if ($fk !== null) {
            DB::statement("ALTER TABLE `file_bin` DROP FOREIGN KEY `{$fk->constraint_name}`");
        }
    }

    public function plan(string $sourcePrefix, ?string $sourceDatabase, Closure $out): void
    {
        $out('PLAN '.self::SNAPSHOT_KEY.': would record MAX(`id`) FROM '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file'));
        if (! $this->inPlace($sourcePrefix, $sourceDatabase)) {
            $out('PLAN '.self::MOVE_KEY.': would move '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file_bin').' into file_bin');
        }
        $out('PLAN '.self::REWIRE_KEY.': would re-point file_bin.file_id FK onto files');
    }

    private function inPlace(string $sourcePrefix, ?string $sourceDatabase): bool
    {
        return $sourceDatabase === null && $sourcePrefix === '';
    }

    private function sourceSchema(?string $sourceDatabase): string
    {
        return $sourceDatabase ?? $this->database();
    }

    /** The file_bin.file_id FK on a given schema.table, or null. Detected by referenced table, not name. */
    private function foreignKey(string $schema, string $table): ?object
    {
        return DB::selectOne(
            'select rc.constraint_name as constraint_name, kcu.referenced_table_name as referenced_table
               from information_schema.referential_constraints rc
               join information_schema.key_column_usage kcu
                 on kcu.constraint_schema = rc.constraint_schema and kcu.constraint_name = rc.constraint_name
              where rc.constraint_schema = ? and kcu.table_name = ? and kcu.column_name = ?',
            [$schema, $table, 'file_id'],
        );
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ? limit 1',
            [$schema, $table],
        ) !== null;
    }

    private function appFileBinExists(): bool
    {
        return $this->tableExists($this->database(), 'file_bin');
    }

    private function appFileBinCount(): int
    {
        return $this->appFileBinExists() ? (int) DB::scalar('SELECT COUNT(*) FROM `file_bin`') : 0;
    }

    private function database(): string
    {
        return DB::connection()->getDatabaseName();
    }

    private function checkpoint(string $key): void
    {
        UpgradeState::updateOrCreate(['step_key' => $key], ['status' => UpgradeState::STATUS_COMPLETED]);
    }
}
