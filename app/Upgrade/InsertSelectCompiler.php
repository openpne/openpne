<?php

namespace App\Upgrade;

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
        $columns = $step->columns();

        $targetColumns = implode(', ', array_map(
            static fn (string $name): string => "`{$name}`",
            array_keys($columns),
        ));

        $selectList = implode(', ', array_map(
            static fn (Column $column): string => $column->selectSql(),
            array_values($columns),
        ));

        $source = $this->qualifiedName($sourceDatabase, $sourcePrefix, $step->sourceTable());
        $target = $this->qualifiedName($targetDatabase, $targetPrefix, $step->targetTable());

        $sql = "INSERT INTO {$target} ({$targetColumns})\nSELECT {$selectList}\nFROM {$source}";

        if ($step->filter() !== null) {
            $sql .= "\nWHERE {$step->filter()}";
        }

        return $sql;
    }

    private function qualifiedName(?string $database, string $prefix, string $table): string
    {
        $name = "`{$prefix}{$table}`";

        return $database !== null ? "`{$database}`.{$name}" : $name;
    }
}
