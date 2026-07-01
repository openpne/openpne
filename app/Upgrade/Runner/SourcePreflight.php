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
 * Introspection is read-only via information_schema qualified by the source database + prefix:
 * Schema::hasTable() binds to the connection's own (empty-prefix) database and cannot see a
 * --source-prefix / --source-database table. MySQL-only, like the runner.
 */
final class SourcePreflight
{
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
