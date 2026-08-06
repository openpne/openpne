<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the live OpenPNE 3 source before the runner walks the steps, bridging the gap between the
 * assumed fixture schema (OpenPNE 3.10.19 + canonical plugins) and a real site:
 *
 *  - a CORE source table or a consumed FROM column missing → hard error (incomplete dump, an
 *    OpenPNE 3 older than 3.6.x, or a customisation that dropped it). The run aborts before any write.
 *  - an OPTIONAL plugin group (StepRegistry::optionalPluginSources) fully absent → the plugin is not
 *    installed → its tables are created empty (ensureExists) so the steps no-op and FileUpgrade's
 *    owner subqueries resolve against an empty table.
 *  - an OPTIONAL plugin group partially present → an old/corrupt plugin → hard error naming its floor.
 *
 * It also counts the KV config rows whose `name` the upgrade does not recognise. That is a warning,
 * not an error: a third-party plugin or a source customisation is a legitimate reason for the source
 * to hold names OpenPNE 4 has no home for, and the operator decides whether losing them matters.
 *
 * Introspection is read-only via information_schema qualified by the source database + prefix:
 * Schema::hasTable() binds to the connection's own (empty-prefix) database and cannot see a
 * --source-prefix / --source-database table. MySQL-only, like the runner.
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
     * it recognises without enumerating (or null). notification_mail is not a KV table, but its step
     * carries only the names in a `name IN (…)` filter — so a name outside the recognised set is just as
     * invisible per-step as an unrecognised config key, and gets the same warning.
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

        // consumedSourceColumns() attributes every column to the step's own FROM table, so a table
        // reached only by correlated subquery gets no column check — and both KV config tables are
        // read that way (community_config always, member_config whenever the step set excludes the
        // ones that select FROM it). Their `name` is read by those subqueries and by the scan below,
        // so require it here rather than letting either be where a customised source blows up.
        foreach (self::CONFIG_NAME_TABLES as $table) {
            if (isset($present[$table])) {
                $required[$table]['name'] = true;
            }
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
     * Row counts per unrecognised `name`, for the scanned tables this run reads. A name outside the
     * recognised set is invisible to the per-step column audit — the KV tables have no per-name column
     * and notification_mail's step filters by name — which is what this replaces at run time.
     *
     * Deliberately not part of inspect(): the scan itself reads `name`, so a source missing that
     * column has to reach inspect()'s structural verdict — call this only once that comes back
     * clean. That also keeps it off verify-upgrade, which has no use for the warning.
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
