<?php

namespace App\Upgrade\Verify;

use App\Auth\PasswordScheme;
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
 * Read-only gate for openpne:verify-upgrade, run before switchover; it re-counts the live source and
 * target rather than trusting the runner's self-report (Checks A/B/C in docs/internals/upgrade.md,
 * "Verify"). Console-free and registry-injectable, like the runner.
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

        // A fully absent optional plugin group is a legitimate state (the runner ran its steps against
        // an empty table and dropped it), so those tables are excluded from the COUNTs rather than
        // failed or thrown on.
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

        // file_bin is no step's target, so its check is gated on this run migrating files.
        if (in_array('files', $this->targetTables(), true)) {
            $this->verifyFileBin($report, $out);
        }

        $this->verifyPasswords($report, $out);

        return $report;
    }

    /**
     * The wrap pass's terminal invariant (Check C): no bare OpenPNE 3 MD5 at rest, every md5_bcrypt
     * row holds a bcrypt string, and no row carries an unknown scheme.
     */
    private function verifyPasswords(VerifyReport $report, Closure $out): void
    {
        $targets = $this->targetTables();

        foreach (['members', 'admin_users'] as $table) {
            if (! in_array($table, $targets, true)) {
                continue;
            }

            $bare = (int) DB::table($table)->whereRaw("`password` REGEXP '^[0-9a-f]{32}\$'")->count();
            $this->record($report, $out, "passwords:{$table}:wrapped", $bare === 0,
                $bare === 0 ? 'no bare-MD5 rows' : "{$bare} bare-MD5 row(s) remain — the password wrap pass has not completed");

            // Strict bcrypt shape, and NULL fails too — NOT LIKE would skip a flagged
            // NULL and accept a merely-'$2'-prefixed string the hasher cannot parse.
            $malformed = (int) DB::table($table)
                ->where('password_scheme', PasswordScheme::Md5Bcrypt->value)
                ->where(function ($query): void {
                    $query->whereNull('password')
                        ->orWhereRaw('`password` NOT REGEXP ?', ['^\\$2[aby]\\$[0-9]{2}\\$[./A-Za-z0-9]{53}$']);
                })
                ->count();
            $this->record($report, $out, "passwords:{$table}:scheme", $malformed === 0,
                $malformed === 0 ? 'every md5_bcrypt row holds a bcrypt hash' : "{$malformed} md5_bcrypt row(s) do not hold a bcrypt hash");

            $unknown = (int) DB::table($table)
                ->whereNotNull('password_scheme')
                ->where('password_scheme', '!=', PasswordScheme::Md5Bcrypt->value)
                ->count();
            $this->record($report, $out, "passwords:{$table}:known_schemes", $unknown === 0,
                $unknown === 0 ? 'no unknown password_scheme values' : "{$unknown} row(s) carry an unknown password_scheme");
        }
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

        // The COUNT reads only the FROM table and the filter, so a missing table matters only when one
        // of those is an absent optional plugin (counted as 0), not when a column subquery reads one.
        $countTables = array_merge([$step->sourceTable()], SourceRef::tablesIn($step->effectiveFilter() ?? ''));
        $sourceN = array_intersect($countTables, $absent) !== []
            ? 0
            : $this->sourceCount($step, $options);
        $affectedN = (int) $state->rows_affected;

        // Only the rows the step owns: a sibling step or the runner (the surface_mode stamp) can also
        // write the target table, and counting those rows would read as target drift.
        $target = DB::table($step->targetTable());
        if ($step->targetFilter() !== null) {
            $target->whereRaw($step->targetFilter());
        }
        $targetN = (int) $target->count();

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
        if ($step->effectiveFilter() !== null) {
            $sql .= " WHERE {$step->effectiveFilter()}";
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
