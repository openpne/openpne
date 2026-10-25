<?php

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Migrates the OpenPNE 3 `file_bin` BLOBs without rewriting a byte: snapshot, move (RENAME of a
 * prefixed / other-database source), rewire of the file_id FK from `file` onto `files`
 * (docs/internals/upgrade.md, "file_bin"). Idempotent from information_schema state (FK target,
 * source table presence) so a resume or --force-restart re-runs cleanly; MySQL only.
 */
final class FileBinMigration
{
    private const SNAPSHOT_KEY = 'file_id_max_snapshot';

    private const MOVE_KEY = 'file_bin_move';

    private const REWIRE_KEY = 'file_bin_rewire';

    private const MISMATCH_SAMPLE = 5;

    /**
     * Read-only; returns the error that aborts the run before any write, or null. Call only after
     * SourcePreflight has passed, so the source `file` table is present to COUNT.
     */
    public function preflight(string $sourcePrefix, ?string $sourceDatabase): ?string
    {
        $fileTable = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file');
        $fileRows = (int) DB::scalar("SELECT COUNT(*) FROM {$fileTable}");

        // The bytes are in the source file_bin on a fresh run, else in the app's own (in-place, or
        // already moved); an app file_bin absent after a crash mid-move counts as 0.
        $sourcePresent = $this->tableExists($this->sourceSchema($sourceDatabase), $sourcePrefix.'file_bin');
        $binTable = $sourcePresent
            ? InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'file_bin')
            : ($this->appFileBinExists() ? '`file_bin`' : null);
        $bytesRows = $binTable === null ? 0 : (int) DB::scalar("SELECT COUNT(*) FROM {$binTable}");
        $paired = $binTable === null ? 0 : (int) DB::scalar("SELECT COUNT(*) FROM {$fileTable} AS `f` JOIN {$binTable} AS `b` ON `b`.`file_id` = `f`.`id`");

        if ($fileRows !== $paired || $bytesRows !== $paired) {
            $error = ($fileRows === $bytesRows
                ? "file and file_bin both have {$fileRows} rows but they do not pair up"
                : "file has {$fileRows} rows but file_bin has {$bytesRows}")
                ." — every file's bytes must be a file_bin row (DB-blob storage; a filesystem-storage site is unsupported).";

            if ($binTable !== null) {
                $error .= self::unpaired($fileTable, 'id', $binTable, 'file_id', $fileRows - $paired)
                    .self::unpaired($binTable, 'file_id', $fileTable, 'id', $bytesRows - $paired);
            }

            return $error.($sourcePresent
                ? ' Restore a complete dump (one cut short inside file_bin looks like this), or delete the unpaired rows in the source and re-run.'
                : ' The bytes were already moved into this database\'s file_bin; fix the source file rows and re-run, or --force-restart from a fresh restore.');
        }

        if (! $this->inPlace($sourcePrefix, $sourceDatabase) && $sourcePresent && $this->appFileBinCount() > 0) {
            return 'the app file_bin already has rows — refusing to drop it to move the source file_bin in; --force-restart from a clean state instead.';
        }

        return null;
    }

    private static function unpaired(string $table, string $key, string $other, string $otherKey, int $total): string
    {
        if ($total <= 0) {
            return '';
        }

        $ids = array_map(
            static fn (object $row): int => (int) $row->{$key},
            DB::select("SELECT `l`.`{$key}` FROM {$table} AS `l` LEFT JOIN {$other} AS `r` ON `r`.`{$otherKey}` = `l`.`{$key}` WHERE `r`.`{$otherKey}` IS NULL ORDER BY `l`.`{$key}` LIMIT ".self::MISMATCH_SAMPLE),
        );
        $more = $total - count($ids);

        return " {$total} {$table} row(s) have no {$other} row ({$key} ".implode(', ', $ids).($more > 0 ? " and {$more} more" : '').').';
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
