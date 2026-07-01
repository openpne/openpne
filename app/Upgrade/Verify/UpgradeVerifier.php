<?php

namespace App\Upgrade\Verify;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\SourcePreflight;
use App\Upgrade\SourceRef;
use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-migration integrity check for openpne:verify-upgrade — a read-only gate run before switchover.
 * It does not trust the runner's self-report: it independently re-counts the live source and target.
 *
 *  - Check A (per step): source rows matching the step's filter == the recorded rows_affected == the
 *    target row count. A divergence is source drift (source mutated after the run), target corruption,
 *    or a step that never completed.
 *  - Check B (file_bin): every file has its bytes and files.byte_size == LENGTH(file_bin.bin), and the
 *    FK is rewired onto files.
 *
 * Console-free (an output closure) and registry-injectable (tests pass a step subset), like the runner.
 */
final class UpgradeVerifier
{
    /** @param  list<UpgradeStep>|null  $steps */
    public function __construct(
        private readonly InsertSelectCompiler $compiler,
        private readonly ?array $steps = null,
    ) {}

    public function verify(RunOptions $options, ?Closure $out = null): VerifyReport
    {
        $out ??= static fn (string $line): null => null;
        $report = new VerifyReport;
        $steps = $this->steps();

        // The same read-only source inspection the runner does. A fully-absent optional plugin group is
        // a legitimate "not installed" state — the runner ensure-exists'd an empty source, ran 0 rows,
        // and dropped it — so those source tables are absent by design (not a failure, and not to be
        // COUNTed: that would throw on the missing table). Required / partial problems are failures.
        $preflight = (new SourcePreflight($steps, SourceSchema::default()))->inspect($options->sourcePrefix, $options->sourceDatabase);
        $absent = $preflight->absentOptional;

        foreach (array_merge($preflight->tableErrors, $preflight->columnErrors) as $error) {
            $this->record($report, $out, 'source', false, $error);
        }

        // A missing required table / column (or a partial plugin) means the step COUNTs below would hit
        // the missing table — so a broken source fails the gate here and does not proceed to Check A/B.
        if ($preflight->hasErrors()) {
            return $report;
        }

        foreach ($steps as $step) {
            if ($step->pendingTargets() !== []) {
                continue;
            }
            $this->verifyStep($report, $out, $step, $options, $absent);
        }

        // file_bin is not a step; its bytes migrate by FK rewire/rename. Only when this run migrates files.
        if (in_array('files', $this->targetTables(), true)) {
            $this->verifyFileBin($report, $out);
        }

        return $report;
    }

    /** @param  list<string>  $absent */
    private function verifyStep(VerifyReport $report, Closure $out, UpgradeStep $step, RunOptions $options, array $absent): void
    {
        $key = class_basename($step);
        $state = UpgradeState::where('step_key', $key)->first();

        if ($state === null || $state->status !== UpgradeState::STATUS_COMPLETED) {
            $this->record($report, $out, $key, false, 'not completed — no completed upgrade-state row');

            return;
        }

        // The COUNT reads only the FROM table and the filter (not the columns' subqueries — those feed
        // the INSERT's SELECT, not this count). So a step migrated nothing, and its COUNT would hit a
        // missing table, only when its FROM table or a filter subquery table is an absent optional
        // plugin — treat that as 0 (0 == 0 == 0 then passes). A core FROM whose columns merely read an
        // absent optional owner (e.g. FileUpgrade) still counts normally.
        $countTables = array_merge([$step->sourceTable()], SourceRef::tablesIn($step->filter() ?? ''));
        $sourceN = array_intersect($countTables, $absent) !== []
            ? 0
            : $this->sourceCount($step, $options);
        $targetN = (int) DB::table($step->targetTable())->count();
        $affectedN = (int) $state->rows_affected;

        if ($sourceN === $affectedN && $affectedN === $targetN) {
            $this->record($report, $out, $key, true, "{$targetN} rows");

            return;
        }

        $diagnosis = [];
        if ($sourceN !== $affectedN) {
            $diagnosis[] = 'source drift';
        }
        if ($targetN !== $affectedN) {
            $diagnosis[] = 'target mismatch';
        }
        $this->record($report, $out, $key, false, implode(' + ', $diagnosis).": source={$sourceN} rows_affected={$affectedN} target={$targetN}");
    }

    private function sourceCount(UpgradeStep $step, RunOptions $options): int
    {
        // Mirror the compiler's FROM + WHERE: the source is aliased to its bare name so the filter's
        // correlated references resolve, and SourceRef tokens are qualified the same way.
        $source = InsertSelectCompiler::qualify($options->sourceDatabase, $options->sourcePrefix, $step->sourceTable());
        $sql = "SELECT COUNT(*) FROM {$source} AS `{$step->sourceTable()}`";
        if ($step->filter() !== null) {
            $sql .= " WHERE {$step->filter()}";
        }

        return (int) DB::scalar($this->compiler->resolveSourceRefs($sql, $options->sourcePrefix, $options->sourceDatabase));
    }

    private function verifyFileBin(VerifyReport $report, Closure $out): void
    {
        // A files-migrating run with no file_bin table is a broken/incomplete schema — report it as a
        // failure rather than throwing on the DB::table('file_bin') calls below.
        if (! Schema::hasTable('file_bin')) {
            $this->record($report, $out, 'file_bin:count', false, 'file_bin table is missing');

            return;
        }

        $files = (int) DB::table('files')->count();
        $bins = (int) DB::table('file_bin')->count();
        $this->record($report, $out, 'file_bin:count', $files === $bins,
            $files === $bins ? "{$bins} files have bytes" : "files={$files} file_bin={$bins}");

        $mismatch = (int) DB::scalar(
            'SELECT COUNT(*) FROM files f JOIN file_bin b ON b.file_id = f.id WHERE b.bin IS NULL OR f.byte_size <> LENGTH(b.bin)'
        );
        $this->record($report, $out, 'file_bin:byte_size', $mismatch === 0,
            $mismatch === 0 ? 'byte_size matches LENGTH(bin)' : "{$mismatch} files where byte_size <> LENGTH(bin)");

        $ref = DB::selectOne(
            'select kcu.referenced_table_name as referenced_table
               from information_schema.referential_constraints rc
               join information_schema.key_column_usage kcu
                 on kcu.constraint_schema = rc.constraint_schema and kcu.constraint_name = rc.constraint_name
              where rc.constraint_schema = ? and kcu.table_name = ? and kcu.column_name = ?',
            [DB::connection()->getDatabaseName(), 'file_bin', 'file_id'],
        );
        $rewired = ($ref->referenced_table ?? null) === 'files';
        $this->record($report, $out, 'file_bin:fk', $rewired,
            $rewired ? 'file_id references files' : 'file_id FK is not rewired onto files');
    }

    private function record(VerifyReport $report, Closure $out, string $name, bool $pass, string $detail): void
    {
        $pass ? $report->pass($name, $detail) : $report->fail($name, $detail);
        $out(($pass ? 'PASS' : 'FAIL')." {$name}".($detail !== '' ? ": {$detail}" : ''));
    }

    /** @return list<UpgradeStep> */
    private function steps(): array
    {
        return $this->steps ?? StepRegistry::all();
    }

    /** @return list<string> */
    private function targetTables(): array
    {
        return array_values(array_unique(array_map(static fn (UpgradeStep $s): string => $s->targetTable(), $this->steps())));
    }
}
