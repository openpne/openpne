<?php

namespace App\Upgrade;

use LogicException;

/**
 * Compiles an UpgradeStep into the `INSERT ... SELECT` the upgrade runs: a set-based copy inside one
 * MySQL instance, source and target qualified by an optional table prefix and database.
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

        // The FROM table is aliased to its bare name so a step's correlated subqueries reference the
        // outer row by that name under a prefix or another database.
        $source = self::qualify($sourceDatabase, $sourcePrefix, $step->sourceTable())." AS `{$step->sourceTable()}`";
        $target = self::qualify($targetDatabase, $targetPrefix, $step->targetTable());

        $sql = "INSERT INTO {$target} ({$targetColumns})\nSELECT {$selectList}\nFROM {$source}";

        if ($step->effectiveFilter() !== null) {
            $sql .= "\nWHERE {$step->effectiveFilter()}";
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
            SourceRef::PATTERN,
            static fn (array $m): string => self::qualify($sourceDatabase, $sourcePrefix, $m[1]),
            $sql,
        );
    }

    /**
     * The backtick-quoted table name, database-qualified when given; public so the preflight creates
     * an ensure-exists table at exactly the name a compiled query reads.
     */
    public static function qualify(?string $database, string $prefix, string $table): string
    {
        $name = "`{$prefix}{$table}`";

        return $database !== null ? "`{$database}`.{$name}" : $name;
    }
}
