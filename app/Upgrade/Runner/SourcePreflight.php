<?php

namespace App\Upgrade\Runner;

use App\Upgrade\ActiveMember;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the live OpenPNE 3 source before the runner writes: a missing core table or column, or a
 * partial optional plugin group, aborts, and a fully absent optional group is created empty so its
 * steps no-op (docs/internals/upgrade.md, "Source preflight"). Introspection goes through
 * information_schema qualified by the source database and prefix, because Schema::hasTable() sees
 * only the connection's own database.
 */
final class SourcePreflight
{
    /**
     * The KV config tables whose recognised names are enumerable, so an unrecognised one can be
     * counted. Both are read by correlated subquery, so their `name` is also required structurally.
     */
    private const CONFIG_NAME_TABLES = ['member_config', 'community_config'];

    /**
     * The source tables scanned for names the upgrade does not recognise, each with the one name prefix
     * recognised without enumerating (or null). notification_mail is not a KV table, but its step
     * carries only the names in a `name IN (…)` filter, so an unrecognised name is just as invisible
     * per step.
     */
    private const NAME_SCAN_TABLES = [
        'member_config' => null,
        'community_config' => null,
        'notification_mail' => StepRegistry::NOTIFICATION_MAIL_MOBILE_PREFIX,
    ];

    /** @param  list<UpgradeStep>  $steps */
    public function __construct(
        private readonly array $steps,
        private readonly SourceSchema $schema,
    ) {}

    public function inspect(string $sourcePrefix, ?string $sourceDatabase): SourcePreflightReport
    {
        $readTables = $this->readTables();

        $present = [];
        foreach ($readTables as $table) {
            $present[$table] = $this->tableExists($table, $sourcePrefix, $sourceDatabase);
        }

        $tableErrors = [];
        $absentOptional = [];
        $optional = [];

        // Plugin groups, scoped to the tables this run actually reads (a step subset may read only
        // some of a plugin's tables — so a not-read table's absence must not look like a partial group).
        foreach (StepRegistry::optionalPluginSources() as $plugin => $meta) {
            $group = array_values(array_intersect($meta['tables'], $readTables));
            if ($group === []) {
                continue;
            }
            $optional = array_merge($optional, $group);

            $missing = array_values(array_filter($group, static fn (string $t): bool => ! $present[$t]));
            if ($missing === $group) {
                $absentOptional = array_merge($absentOptional, $group); // none present → not installed
            } elseif ($missing !== []) {
                $tableErrors[] = self::partialPluginMessage($plugin, $meta['floor'], $missing);
            }
        }

        // Every other read table is core and required; absence is a broken/old source.
        foreach (array_diff($readTables, $optional) as $table) {
            if (! $present[$table]) {
                $tableErrors[] = self::missingTableMessage($table);
            }
        }

        return new SourcePreflightReport(
            $tableErrors,
            $this->columnErrors($present, $sourcePrefix, $sourceDatabase),
            array_values(array_unique($absentOptional)),
        );
    }

    /**
     * @param  list<string>  $tables
     * @return list<string> the names created, for drop()
     */
    public function ensureExists(array $tables, string $sourcePrefix, ?string $sourceDatabase, Closure $out): array
    {
        $created = [];
        foreach ($tables as $table) {
            $qualified = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, $table);
            // Re-point only the leading `CREATE TABLE `name`` at the qualified source name.
            $ddl = preg_replace(
                '/^CREATE TABLE `'.preg_quote($table, '/').'`/',
                "CREATE TABLE {$qualified}",
                $this->schema->createStatement($table, withoutForeignKeys: true),
                1,
            );
            DB::statement($ddl);
            $created[] = $table;
            $out('WARN '.self::absentPluginMessage($table));
        }

        return $created;
    }

    /** @param  list<string>  $tables */
    public function drop(array $tables, string $sourcePrefix, ?string $sourceDatabase): void
    {
        foreach ($tables as $table) {
            DB::statement('DROP TABLE IF EXISTS '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, $table));
        }
    }

    public static function missingTableMessage(string $table): string
    {
        return "required source table `{$table}` is missing — restore the full OpenPNE 3 dump, or upgrade OpenPNE 3 to >= 3.6.x if it is an older install.";
    }

    public static function missingColumnMessage(string $table, string $column): string
    {
        return "source `{$table}`.`{$column}` is missing — the OpenPNE 3 source is an older or customised version; upgrade it to a supported version (core >= 3.6.x; plugins per the upgrade docs) first.";
    }

    /** @param  list<string>  $missing */
    public static function partialPluginMessage(string $plugin, string $floor, array $missing): string
    {
        return "{$plugin} is installed but missing ".implode(', ', $missing)." — upgrade {$plugin} to >= {$floor}, or restore the full dump.";
    }

    public static function absentPluginMessage(string $table): string
    {
        return "source table `{$table}` absent — the plugin is not installed; created empty so its step is a no-op (not migrated).";
    }

    public static function unknownSourceNameMessage(string $table, string $name, int $rows): string
    {
        return "source `{$table}` holds {$rows} row(s) named `{$name}`, which the upgrade does not recognise — a third-party plugin or a source customisation. Not migrated; `openpne:upgrade-matrix` lists the names that are.";
    }

    public static function inactiveMemberReferenceMessage(string $reference, int $rows): string
    {
        return "source `{$reference}` has {$rows} row(s) pointing at a member OpenPNE 3 never activated (or at no member at all). Stock OpenPNE 3 cannot produce those — an inactive account cannot post — so the upgrade will not guess whether to drop them with their comments and attachments. Delete or reassign them in the source, then re-run.";
    }

    public static function danglingMemberReferenceMessage(string $reference, int $rows): string
    {
        return "source `{$reference}` has {$rows} row(s) whose member is missing from the source entirely — an incomplete dump or a broken foreign key. Restore the full OpenPNE 3 dump, or delete those rows, then re-run.";
    }

    /** @return list<string> the names the upgrade recognises in one NAME_SCAN_TABLES table */
    private static function knownNames(string $table): array
    {
        return match ($table) {
            'member_config' => StepRegistry::knownMemberConfigNames(),
            'community_config' => StepRegistry::knownCommunityConfigNames(),
            'notification_mail' => StepRegistry::knownNotificationMailNames(),
        };
    }

    /** @return list<string> */
    private function readTables(): array
    {
        $tables = [];
        foreach ($this->steps as $step) {
            foreach ($step->readSourceTables() as $table) {
                $tables[$table] = true;
            }
        }

        // A REFUSE check resolves against `member` even when no step's own SQL names it (a content step
        // has no guard), so declaring it here makes a source without it abort on the structural check
        // instead of mid-count.
        foreach (ActiveMember::references() as $reference => $meta) {
            [$table] = explode('.', $reference);
            if ($meta['treatment'] === ActiveMember::REFUSE && isset($tables[$table])) {
                $tables['member'] = true;
                break;
            }
        }

        return array_keys($tables);
    }

    /**
     * @param  array<string, bool>  $present
     * @return list<string>
     */
    private function columnErrors(array $present, string $sourcePrefix, ?string $sourceDatabase): array
    {
        // Consumed FROM columns merged across steps that share a FROM table (member_relationship → 3).
        $required = [];
        foreach ($this->steps as $step) {
            foreach ($step->consumedSourceColumns() as $column) {
                $required[$step->sourceTable()][$column] = true;
            }
        }

        // A table reached only by correlated subquery gets no per-step column check, and both KV config
        // tables can be, so their `name` (read by those subqueries and by the unknown-name scan) is
        // required here.
        foreach (self::CONFIG_NAME_TABLES as $table) {
            if (isset($present[$table])) {
                $required[$table]['name'] = true;
            }
        }

        // The active-member checks read columns the per-step check never attributes (a guard reaches
        // `member` by subquery; a REFUSE table may be no step's FROM), so they are required here instead
        // of surfacing as a SQL exception or a silent count.
        $readTables = $this->readTables();
        $readsMember = false;

        foreach ($this->steps as $step) {
            $readsMember = $readsMember || $step->memberRefs() !== [];
        }

        foreach (ActiveMember::references() as $reference => $meta) {
            [$table, $column] = explode('.', $reference);
            if ($meta['treatment'] !== ActiveMember::REFUSE || ! in_array($table, $readTables, true)) {
                continue;
            }

            $readsMember = true;
            if (isset($present[$table])) {
                $required[$table][$column] = true;
                foreach ($meta['scopeColumns'] ?? [] as $scopeColumn) {
                    $required[$table][$scopeColumn] = true;
                }
            }
        }

        if ($readsMember && isset($present['member'])) {
            $required['member']['id'] = true;
            $required['member']['is_active'] = true;
        }

        $errors = [];
        foreach ($required as $table => $columns) {
            if (! ($present[$table] ?? false)) {
                continue; // an absent table is handled by the table check / ensure-exists
            }
            $live = $this->tableColumns($table, $sourcePrefix, $sourceDatabase);
            foreach (array_keys($columns) as $column) {
                if (! in_array($column, $live, true)) {
                    $errors[] = self::missingColumnMessage($table, $column);
                }
            }
        }

        return $errors;
    }

    /**
     * Row counts per unrecognised `name` in the scanned tables this run reads; a name outside the
     * recognised set is invisible to the per-step column audit, and this is its run-time replacement.
     * Not part of inspect(): the scan reads `name`, so call it only on a clean structural verdict
     * (verify has no use for it either).
     *
     * @return array<string, array<string, int>> table => name => rows, busiest name first
     */
    public function unknownSourceNames(string $prefix, ?string $database): array
    {
        $readTables = $this->readTables();

        $unknown = [];
        foreach (self::NAME_SCAN_TABLES as $table => $knownPrefix) {
            if (! in_array($table, $readTables, true) || ! $this->tableExists($table, $prefix, $database)) {
                continue; // not read by this run, or absent — the table checks in inspect() own that case
            }

            $known = self::knownNames($table);
            $bindings = $known;

            // LEFT(), not LIKE: the prefix ends in `_`, which LIKE would read as a single-character
            // wildcard and let `mobilex_foo` pass as recognised.
            $prefixCondition = '';
            if ($knownPrefix !== null) {
                $prefixCondition = ' and left(`name`, ?) <> ?';
                $bindings[] = strlen($knownPrefix);
                $bindings[] = $knownPrefix;
            }

            $rows = DB::select(
                'select `name`, count(*) as `rows` from '.InsertSelectCompiler::qualify($database, $prefix, $table)
                .' where `name` not in ('.implode(', ', array_fill(0, count($known), '?')).')'.$prefixCondition
                .' group by `name` order by `rows` desc, `name` asc',
                $bindings,
            );

            if ($rows !== []) {
                $unknown[$table] = array_combine(
                    array_map(static fn (object $row): string => $row->name, $rows),
                    array_map(static fn (object $row): int => (int) $row->rows, $rows),
                );
            }
        }

        return $unknown;
    }

    /**
     * The member references this run cannot migrate, counted before the first write: REFUSE rows
     * whose member is not activated, and guarded rows whose member is missing from the source (a
     * broken dump the guard would otherwise swallow). Not part of inspect(): it reads columns
     * inspect() establishes, so call it only on a clean structural verdict.
     *
     * @return array{refused: array<string, int>, dangling: array<string, int>} reference => rows
     */
    public function inactiveMemberReferences(string $prefix, ?string $database): array
    {
        $readTables = $this->readTables();
        $compiler = new InsertSelectCompiler;

        $count = function (string $table, string $condition, ?string $scope) use ($compiler, $prefix, $database): int {
            $sql = 'select count(*) from '.InsertSelectCompiler::qualify($database, $prefix, $table)." as `{$table}`"
                ." where {$condition}".($scope !== null ? " and ({$scope})" : '');

            return (int) DB::scalar($compiler->resolveSourceRefs($sql, $prefix, $database));
        };

        $refused = [];
        foreach (ActiveMember::references() as $reference => $meta) {
            [$table, $column] = explode('.', $reference);
            if ($meta['treatment'] !== ActiveMember::REFUSE || ! $this->readsColumn($readTables, $table, $column, $prefix, $database)) {
                continue;
            }

            // A ledger scope replaces the FROM step's filter: the rows reaching a target member
            // column are not always that step's rows (a correlated subquery has its own predicate).
            $scope = $meta['scope'] ?? $this->fromStepFilter($table);
            $rows = $count($table, 'not '.ActiveMember::referenceGuard($table, $column), $scope);
            if ($rows > 0) {
                $refused[$reference] = $rows;
            }
        }

        // One count per reference over the union of its steps' filter() (not effectiveFilter(), whose
        // guards would exclude exactly these rows): several steps can guard one column with different
        // filters, and a per-step count would report one slice as the total.
        $filtersByReference = [];
        foreach ($this->steps as $step) {
            foreach ($step->memberRefs() as $column) {
                $filtersByReference["{$step->sourceTable()}.{$column}"][] = $step->filter();
            }
        }

        $dangling = [];
        foreach ($filtersByReference as $reference => $filters) {
            [$table, $column] = explode('.', $reference);
            if (! $this->readsColumn($readTables, $table, $column, $prefix, $database)) {
                continue;
            }

            // An unfiltered step takes every row, which subsumes any sibling's filter.
            $scope = in_array(null, $filters, true)
                ? null
                : implode(' OR ', array_map(static fn (string $f): string => "({$f})", array_unique($filters)));

            $rows = $count($table, ActiveMember::danglingReference($table, $column), $scope);
            if ($rows > 0) {
                $dangling[$reference] = $rows;
            }
        }

        arsort($refused);
        arsort($dangling);

        return ['refused' => $refused, 'dangling' => $dangling];
    }

    /**
     * The effective filter of the step that selects FROM $table, if any — so a count over that table
     * sees the rows the run actually migrates. Null when no step has it as its FROM (the table is
     * only reached by correlated subquery) or when that step takes every row.
     */
    private function fromStepFilter(string $table): ?string
    {
        foreach ($this->steps as $step) {
            if ($step->sourceTable() === $table) {
                return $step->effectiveFilter();
            }
        }

        return null;
    }

    /** @param  list<string>  $readTables */
    private function readsColumn(array $readTables, string $table, string $column, string $prefix, ?string $database): bool
    {
        return in_array($table, $readTables, true)
            && $this->tableExists($table, $prefix, $database)
            && $this->tableExists('member', $prefix, $database)
            && in_array($column, $this->tableColumns($table, $prefix, $database), true);
    }

    private function tableExists(string $table, string $prefix, ?string $database): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ? limit 1',
            [$database ?? DB::connection()->getDatabaseName(), $prefix.$table],
        ) !== null;
    }

    /** @return list<string> lowercased column names */
    private function tableColumns(string $table, string $prefix, ?string $database): array
    {
        return array_map(
            static fn (object $row): string => strtolower($row->name),
            DB::select(
                'select column_name as name from information_schema.columns where table_schema = ? and table_name = ?',
                [$database ?? DB::connection()->getDatabaseName(), $prefix.$table],
            ),
        );
    }
}
