<?php

namespace App\Upgrade;

/**
 * One feature's OpenPNE 3 → 4 mapping. The subclass is the SSoT: it names the
 * source/target tables, maps each target column, and records accepted gaps.
 *
 * Keeping the mapping in typed PHP (vs. external data) lets expressions reference
 * the runtime enums/models they must agree with — e.g. a visibility CASE built from
 * Visibility::Open->value cannot silently drift from the enum. InsertSelectCompiler
 * turns a step into the set-based SQL the tool runs.
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
     * Target columns whose source mapping is deferred, with the reason. Distinct from
     * targetDefaults() (no source, rely on the schema default): these need a source but
     * it is not resolved yet, so the step is not runnable. The audit accepts them as
     * accounted-for, and InsertSelectCompiler refuses to compile while any remain.
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
     * FROM-row columns referencing `member`.`id` whose row the upgrade drops when that member is one
     * MemberUpgrade skips (see ActiveMember). Declared per step rather than derived from the source
     * FKs because dropping is only right where such a row can exist — a registration artifact
     * (member_config, member_profile, member_image, member_relationship, community_member). A member
     * reference on a content table cannot occur in stock OpenPNE 3 (an inactive account has no
     * SNSMember credential, so it posts nothing) and dropping there would mean dropping the row's
     * children too; SourcePreflight refuses to start on one instead. UpgradeMatrixAuditTest holds
     * every source reference to one treatment or the other.
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
     * Optional raw SQL boolean scoping the target rows this step owns, for the verify row-count
     * parity. null = the step owns the whole target table. Required once several steps write one
     * table, or something outside the steps also writes rows into it.
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
