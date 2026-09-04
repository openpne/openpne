<?php

namespace App\Upgrade;

/**
 * One OpenPNE 3 table's mapping onto one OpenPNE 4 table, compiled by InsertSelectCompiler into a
 * single INSERT...SELECT. The mapping is typed PHP so an expression can read the runtime enum it
 * must agree with (docs/internals/upgrade.md).
 */
abstract class UpgradeStep
{
    protected string $source;

    protected string $target;

    public function sourceTable(): string
    {
        return $this->source;
    }

    public function targetTable(): string
    {
        return $this->target;
    }

    /** @return array<string, Column> target column => mapping, in INSERT/SELECT order */
    abstract public function columns(): array;

    /**
     * Source columns/tables intentionally not migrated, with the reason.
     *
     * @return array<string, string> source column or table => reason
     */
    public function gaps(): array
    {
        return [];
    }

    /**
     * Target columns that are intentionally not sourced (new OpenPNE 4 columns that
     * rely on their schema default). Lets the audit flag unhandled target columns
     * without false positives.
     *
     * @return list<string>
     */
    public function targetDefaults(): array
    {
        return [];
    }

    /**
     * Target columns that need a source mapping the step has not resolved, with the reason
     * (targetDefaults() lists the ones that need none). A step with any is not runnable: the compiler
     * refuses it and the runner skips it.
     *
     * @return array<string, string> target column => reason
     */
    public function pendingTargets(): array
    {
        return [];
    }

    /**
     * Optional SQL boolean restricting which source rows are copied (the WHERE clause),
     * e.g. when one source table feeds several target tables by a flag. null = all rows.
     */
    public function filter(): ?string
    {
        return null;
    }

    /**
     * Source columns the filter reads, so they count as consumed in the audit.
     *
     * @return list<string>
     */
    public function filterColumns(): array
    {
        return [];
    }

    /**
     * FROM-row columns referencing `member.id` whose row the guard drops when that member is one the
     * upgrade skips (ActiveMember). Declared only where stock OpenPNE 3 produces such a row (a
     * registration artifact); a content table's inactive reference is refused by the preflight
     * instead (docs/internals/upgrade.md).
     *
     * @return list<string>
     */
    public function memberRefs(): array
    {
        return [];
    }

    /**
     * The step's WHERE clause: its own filter() plus a guard per memberRefs() entry. Everything that
     * reads the filter must read it through here — the compiler emits it, and the verifier counts the
     * source rows with it, so a guard missing from either side would show up as a row-count mismatch.
     */
    final public function effectiveFilter(): ?string
    {
        $clauses = array_map(
            fn (string $column): string => ActiveMember::referenceGuard($this->sourceTable(), $column),
            $this->memberRefs(),
        );

        if ($this->filter() !== null) {
            array_unshift($clauses, '('.$this->filter().')');
        }

        return $clauses === [] ? null : implode(' AND ', $clauses);
    }

    /**
     * SQL boolean scoping the target rows this step owns, for the verify row count; null = the whole
     * table. Required once another step or the runner also writes the table.
     */
    public function targetFilter(): ?string
    {
        return null;
    }

    /** @return list<string> distinct source columns read across mappings and the filter */
    public function consumedSourceColumns(): array
    {
        $used = [];
        foreach ($this->columns() as $column) {
            foreach ($column->uses as $name) {
                $used[$name] = true;
            }
        }
        foreach (array_merge($this->filterColumns(), $this->memberRefs()) as $name) {
            $used[$name] = true;
        }

        return array_keys($used);
    }

    /**
     * @return list<string> distinct OpenPNE 3 source tables this step reads: the FROM table plus
     *                      every SourceRef::table() subquery table in its mappings and filter. The
     *                      source preflight checks these exist before the step runs.
     */
    public function readSourceTables(): array
    {
        $tables = [$this->sourceTable() => true];

        foreach ($this->columns() as $column) {
            foreach (SourceRef::tablesIn($column->expr ?? '') as $table) {
                $tables[$table] = true;
            }
        }

        foreach (SourceRef::tablesIn($this->effectiveFilter() ?? '') as $table) {
            $tables[$table] = true;
        }

        return array_keys($tables);
    }
}
