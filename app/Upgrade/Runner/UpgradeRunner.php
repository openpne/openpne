<?php

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Walks the upgrade steps in dependency order, copying each into the OpenPNE 4 schema and
 * checkpointing it in openpne4_upgrade_state. Console-free (the command passes an output closure) and
 * registry-injectable (tests pass a step subset), so the orchestration is testable on its own.
 *
 * Each step runs in its own transaction wrapping the INSERT...SELECT and the checkpoint write — the
 * whole run cannot be one transaction at OpenPNE 3 data volumes — so completed ⟺ committed and a
 * re-run resumes from the first incomplete step without re-inserting verbatim ids.
 */
final class UpgradeRunner
{
    /** @param list<UpgradeStep>|null $steps */
    public function __construct(
        private readonly InsertSelectCompiler $compiler,
        private readonly ?array $steps = null,
    ) {}

    /**
     * Preflights the source, then walks every step; returns false on an aborting source error or a
     * step failure (resumable). A missing required table/column aborts before any write (dry-run too);
     * an absent optional plugin group is created empty so its steps no-op, and dropped afterwards.
     */
    public function run(RunOptions $options, ?Closure $out = null): bool
    {
        $out ??= static fn (string $line): null => null;

        $preflight = new SourcePreflight($this->steps(), SourceSchema::default());
        $report = $preflight->inspect($options->sourcePrefix, $options->sourceDatabase);

        // file_bin (the BLOBs) has no step, so its bytes-complete check rides alongside the step
        // preflight — but only once the step preflight is clean, since it COUNTs the source `file` the
        // latter guards. Runs only when this run migrates files at all (the whole registry does).
        $fileBin = new FileBinMigration;
        $migratesFiles = in_array('files', $this->targetTables(), true);
        $fileBinError = $migratesFiles && ! $report->hasErrors()
            ? $fileBin->preflight($options->sourcePrefix, $options->sourceDatabase)
            : null;

        foreach (array_merge($report->tableErrors, $report->columnErrors, $fileBinError !== null ? [$fileBinError] : []) as $error) {
            $out("ERROR {$error}");
        }

        if ($report->hasErrors() || $fileBinError !== null) {
            $out('Aborted: the OpenPNE 3 source did not pass preflight; nothing was migrated.');

            return false;
        }

        if ($options->dryRun) {
            foreach ($report->absentOptional as $table) {
                $out("PLAN would create empty source table `{$table}` (".SourcePreflight::absentPluginMessage($table).')');
            }
            if ($migratesFiles) {
                $fileBin->plan($options->sourcePrefix, $options->sourceDatabase, $out);
            }

            return $this->walk($options, $out);
        }

        // Only now that the source is verified do we clear targets for --force-restart — otherwise a
        // bad source would let the restart delete existing data and then abort on the preflight.
        if ($options->forceRestart) {
            $this->reset($out);
        }

        $created = $preflight->ensureExists($report->absentOptional, $options->sourcePrefix, $options->sourceDatabase, $out);

        try {
            if ($migratesFiles) {
                $fileBin->snapshot($options->sourcePrefix, $options->sourceDatabase, $out);
            }

            $walked = $this->walk($options, $out);

            // Migrate the BLOBs only after the walk: FileUpgrade (first step) has populated `files`, so
            // the move + the FK rewire's existing-row validation resolve. No later step touches file_bin.
            if ($walked && $migratesFiles) {
                $fileBin->move($options->sourcePrefix, $options->sourceDatabase, $out);
                $fileBin->rewire($out);
            }

            return $walked;
        } finally {
            $preflight->drop($created, $options->sourcePrefix, $options->sourceDatabase);
        }
    }

    /** The per-step loop: skip not-runnable / already-completed, else compile + (plan or run) each. */
    private function walk(RunOptions $options, Closure $out): bool
    {
        foreach ($this->steps() as $step) {
            $key = class_basename($step);

            if ($step->pendingTargets() !== []) {
                $out("SKIP {$key}: not runnable (pending: ".implode(', ', array_keys($step->pendingTargets())).')');

                continue;
            }

            if ($this->isCompleted($key)) {
                $out("SKIP {$key}: already completed");

                continue;
            }

            $sql = $this->compiler->compile($step, $options->sourcePrefix, '', $options->sourceDatabase, null);

            if ($options->dryRun) {
                $out("PLAN {$key}:");
                $out($sql);

                continue;
            }

            if (! $this->runStep($key, $sql, $out)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resets for --force-restart: clears the upgrade-owned target tables (verbatim ids would otherwise
     * collide on re-insert) and the checkpoints. DELETE, not TRUNCATE — a FK-referenced table like
     * `files` refuses TRUNCATE even with checks off (error 1701). file_bin is no step's target and
     * holds the OpenPNE 3 BLOBs, so it is never cleared here — but its FK onto `files` is dropped first,
     * so DELETEing `files` cannot cascade into those BLOBs; the re-run's rewire re-adds it.
     */
    public function reset(?Closure $out = null): void
    {
        $out ??= static fn (string $line): null => null;

        $mysql = DB::connection()->getDriverName() === 'mysql';

        if ($mysql && in_array('files', $this->targetTables(), true)) {
            (new FileBinMigration)->dropForeignKey();
        }

        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach ($this->targetTables() as $table) {
                DB::table($table)->delete();
            }
        } finally {
            if ($mysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        UpgradeState::query()->delete();
        $out('Reset: cleared upgrade state and target tables.');
    }

    /** @return list<string> distinct step target tables in reverse run order (FK-safe delete order). */
    public function targetTables(): array
    {
        $tables = [];

        foreach (array_reverse($this->steps()) as $step) {
            $tables[$step->targetTable()] = true;
        }

        return array_keys($tables);
    }

    private function runStep(string $key, string $sql, Closure $out): bool
    {
        try {
            $affected = DB::transaction(function () use ($key, $sql): int {
                $state = UpgradeState::updateOrCreate(['step_key' => $key], [
                    'status' => UpgradeState::STATUS_RUNNING,
                    'started_at' => now(),
                    'finished_at' => null,
                    'rows_affected' => null,
                    'error' => null,
                ]);

                $affected = DB::affectingStatement($sql);

                $state->update([
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'rows_affected' => $affected,
                    'finished_at' => now(),
                ]);

                return $affected;
            });

            $out("DONE {$key}: {$affected} rows");

            return true;
        } catch (Throwable $e) {
            // The transaction rolled back the partial copy and the running checkpoint; record the
            // failure outside it so a resume sees this step as failed and the earlier ones as done.
            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $out("FAIL {$key}: {$e->getMessage()}");

            return false;
        }
    }

    private function isCompleted(string $key): bool
    {
        return UpgradeState::query()
            ->where('step_key', $key)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }

    /** @return list<UpgradeStep> */
    private function steps(): array
    {
        return $this->steps ?? StepRegistry::all();
    }
}
