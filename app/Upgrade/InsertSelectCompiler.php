<?php

namespace App\Upgrade;

use LogicException;

/**
 * Compiles an UpgradeStep into the `INSERT ... SELECT` the upgrade runs.
 *
 * Set-based copy inside one MySQL instance (the tool's chosen mechanism), not a
 * row-by-row PHP loop. A table prefix is concatenated to the table name; an optional
 * database qualifies it as `db`.`table`, covering the 1-DB-in-place, table-prefix,
 * and same-instance/different-database workflows.
 */
final class InsertSelectCompiler
{
    public function compile(
        UpgradeStep $step,
        string $sourcePrefix = '',
        string $targetPrefix = '',
        ?string $sourceDatabase = null,
        ?string $targetDatabase = null,
    ): string {
        if ($step->pendingTargets() !== []) {
            throw new LogicException(sprintf(
                '%s is not runnable: target columns %s have no source mapping yet.',
                $step::class,
                implode(', ', array_keys($step->pendingTargets())),
            ));
        }

        $columns = $step->columns();

        $targetColumns = implode(', ', array_map(
            static fn (string $name): string => "`{$name}`",
            array_keys($columns),
        ));

        $selectList = implode(', ', array_map(
            static fn (Column $column): string => $column->selectSql(),
            array_values($columns),
        ));

        // The FROM table is aliased to its original name so a step's correlated subqueries can keep
        // referencing the outer row by that bare name even when the physical table is prefixed / in
        // another database; SourceRef tokens carry the same qualification into those subqueries.
        $source = $this->qualifiedName($sourceDatabase, $sourcePrefix, $step->sourceTable())." AS `{$step->sourceTable()}`";
        $target = $this->qualifiedName($targetDatabase, $targetPrefix, $step->targetTable());

        $sql = "INSERT INTO {$target} ({$targetColumns})\nSELECT {$selectList}\nFROM {$source}";

        if ($step->filter() !== null) {
            $sql .= "\nWHERE {$step->filter()}";
        }

        $sql = $this->resolveSourceRefs($sql, $sourcePrefix, $sourceDatabase);

        if (str_contains($sql, '{{src:')) {
            throw new LogicException(sprintf('Unresolved source-table token in compiled SQL for %s: %s', $step::class, $sql));
        }

        return $sql;
    }

    /** Resolve SourceRef::table() placeholders to the prefixed / database-qualified source name. */
    public function resolveSourceRefs(string $sql, string $sourcePrefix = '', ?string $sourceDatabase = null): string
    {
        return preg_replace_callback(
            '/\{\{src:([a-z0-9_]+)\}\}/',
            fn (array $m): string => $this->qualifiedName($sourceDatabase, $sourcePrefix, $m[1]),
            $sql,
        );
    }

    private function qualifiedName(?string $database, string $prefix, string $table): string
    {
        $name = "`{$prefix}{$table}`";

        return $database !== null ? "`{$database}`.{$name}" : $name;
    }
}
