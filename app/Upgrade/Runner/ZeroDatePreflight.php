<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\UpgradeStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Counts, before the first write, the zero dates (`0000-00-00`, or a zero month or day) each step's own
 * SELECT would carry into a date-typed target column: the app's strict sql_mode refuses them, so
 * the step would fail mid-run (docs/internals/upgrade.md, "Source preflight"). MySQL only.
 */
final class ZeroDatePreflight
{
    private const SAMPLE = 5;

    private const DATE_TYPES = ['datetime', 'timestamp', 'date'];

    /** MySQL ER_TRUNCATED_WRONG_VALUE: "Incorrect datetime value". */
    private const INCORRECT_DATETIME = 1292;

    /** @var array<string, list<string>> target table => its date-typed columns */
    private array $dateTargetColumns = [];

    /** @param  list<UpgradeStep>  $steps */
    public function __construct(
        private readonly array $steps,
        private readonly SourceSchema $schema,
        private readonly InsertSelectCompiler $compiler = new InsertSelectCompiler,
    ) {}

    /**
     * Each absent optional table is stood in by a session-scoped TEMPORARY table for the scan, since
     * the run materialises it only after this verdict. It is dropped here again, or the run's own
     * CREATE of that name would land behind the temporary.
     *
     * @param  list<string>  $absentOptional  source tables the run will create empty
     * @return list<string> one error per step and target column, in step order
     */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase, array $absentOptional): array
    {
        $stood = [];
        try {
            foreach ($absentOptional as $table) {
                $qualified = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, $table);
                DB::statement(preg_replace(
                    '/^CREATE TABLE `'.preg_quote($table, '/').'`/',
                    "CREATE TEMPORARY TABLE {$qualified}",
                    $this->schema->createStatement($table, withoutForeignKeys: true),
                    1,
                ));
                $stood[] = $qualified;
            }

            $errors = [];
            foreach ($this->steps as $step) {
                if ($step->pendingTargets() === []) {
                    array_push($errors, ...$this->inspectStep($step, $sourcePrefix, $sourceDatabase));
                }
            }

            return $errors;
        } finally {
            foreach ($stood as $qualified) {
                DB::statement("DROP TEMPORARY TABLE IF EXISTS {$qualified}");
            }
        }
    }

    /** @return list<string> */
    private function inspectStep(UpgradeStep $step, string $sourcePrefix, ?string $sourceDatabase): array
    {
        $columns = $step->columns();
        $targets = array_values(array_intersect(array_keys($columns), $this->dateTargetColumns($step->targetTable())));
        if ($targets === []) {
            return [];
        }

        $select = $this->compiler->compileSelect($step, $sourcePrefix, $sourceDatabase);
        $sums = implode(', ', array_map(
            static fn (string $column): string => 'SUM(CASE WHEN '.self::zeroPredicate("`s`.`{$column}`")." THEN 1 ELSE 0 END) AS `{$column}`",
            $targets,
        ));
        $counts = DB::selectOne("SELECT {$sums} FROM ({$select}) AS `s`");
        $warned = $this->incorrectDatetimeWarnings();

        $errors = [];
        foreach ($targets as $target) {
            $rows = (int) $counts->{$target};
            if ($rows === 0) {
                continue;
            }

            $ids = isset($columns['id'])
                ? array_map(
                    static fn (object $row): int => (int) $row->id,
                    DB::select("SELECT `s`.`id` FROM ({$select}) AS `s` WHERE ".self::zeroPredicate("`s`.`{$target}`").' ORDER BY `s`.`id` LIMIT '.self::SAMPLE),
                )
                : [];

            $sourceColumns = array_values(array_intersect($columns[$target]->uses, $this->schema->dateColumns($step->sourceTable())));
            $nullable = $sourceColumns !== [] && array_diff($sourceColumns, $this->schema->nullableColumns($step->sourceTable())) === [];

            $errors[] = self::zeroDateMessage(class_basename($step), $step->targetTable(), $target, $step->sourceTable(), $sourceColumns, $columns[$target]->source === null, $rows, $ids, $nullable);
        }

        if ($errors === [] && $warned !== []) {
            $errors[] = self::zeroDateSubqueryMessage(class_basename($step), $warned, array_values(array_diff($step->readSourceTables(), [$step->sourceTable()])));
        }

        return $errors;
    }

    /**
     * A zero date a CASE or COALESCE meets through a subquery is converted to NULL with a 1292 warning
     * the INSERT turns into an error, so the predicate never sees it and the warning list is read instead.
     * SHOW WARNINGS has no prepared-statement form (1295), so it goes out with emulated prepares.
     *
     * @return list<string> the distinct values the warnings name
     */
    private function incorrectDatetimeWarnings(): array
    {
        $pdo = DB::connection()->getReadPdo();
        $emulate = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $warnings = $pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_OBJ);
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        }

        $values = [];
        foreach ($warnings as $warning) {
            if ((int) $warning->Code === self::INCORRECT_DATETIME && preg_match("/'([^']*)'/", $warning->Message, $m) === 1) {
                $values[$m[1]] = true;
            }
        }

        return array_keys($values);
    }

    /** True for a zero month or day (a year 0000 with both is a value strict mode accepts); a NULL is neither. No date literal: strict mode refuses one in a comparison (1525). */
    public static function zeroPredicate(string $expr): string
    {
        return "(MONTH({$expr}) = 0 OR DAY({$expr}) = 0)";
    }

    /**
     * @param  list<string>  $sourceColumns  the date columns of the source table the mapping reads
     * @param  bool  $expression  the mapping is an expression, which may take the value from a subquery instead
     * @param  list<int>  $ids
     */
    public static function zeroDateMessage(string $step, string $targetTable, string $targetColumn, string $sourceTable, array $sourceColumns, bool $expression, int $rows, array $ids, bool $nullable): string
    {
        $named = implode(', ', array_map(static fn (string $c): string => "`{$sourceTable}`.`{$c}`", $sourceColumns));
        $source = match (true) {
            $sourceColumns === [] => "a row {$step} reads by subquery",
            $expression => "{$named} or a row {$step} reads by subquery",
            default => $named,
        };
        $sample = $ids === [] ? '' : ' (e.g. id '.implode(', ', $ids).')';

        return "source {$source} holds a zero date such as 0000-00-00 on {$rows} row(s) {$step} copies into `{$targetTable}`.`{$targetColumn}`{$sample}"
            .' — MySQL\'s strict mode, which OpenPNE 4 runs under, refuses it, so the step would fail mid-run. Set a real time in the source'
            .($nullable ? ' (or NULL)' : '').', then re-run.';
    }

    /**
     * @param  list<string>  $values  the values the warnings name
     * @param  list<string>  $subqueryTables  the source tables the step reads beside its own
     */
    public static function zeroDateSubqueryMessage(string $step, array $values, array $subqueryTables): string
    {
        $tables = implode(', ', array_map(static fn (string $t): string => "`{$t}`", $subqueryTables));

        return "{$step} reads a zero date (".implode(', ', $values).") through a subquery over {$tables}"
            .' — MySQL\'s strict mode, which OpenPNE 4 runs under, refuses it, so the step would fail mid-run. Set a real time (or NULL) on the row that holds it, then re-run.';
    }

    /** @return list<string> */
    private function dateTargetColumns(string $table): array
    {
        return $this->dateTargetColumns[$table] ??= array_values(array_map(
            static fn (array $column): string => $column['name'],
            array_filter(Schema::getColumns($table), static fn (array $column): bool => in_array(strtolower($column['type_name']), self::DATE_TYPES, true)),
        ));
    }
}
