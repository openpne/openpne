<?php

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
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

    /** Runs every step; returns false if one failed (the run aborts there, resumable). */
    public function run(RunOptions $options, ?Closure $out = null): bool
    {
        $out ??= static fn (string $line): null => null;

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
     * holds the OpenPNE 3 BLOBs, so it is never cleared here.
     */
    public function reset(?Closure $out = null): void
    {
        $out ??= static fn (string $line): null => null;

        $mysql = DB::connection()->getDriverName() === 'mysql';

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
