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

    /** @return list<string> distinct source columns read across all mappings */
    public function consumedSourceColumns(): array
    {
        $used = [];
        foreach ($this->columns() as $column) {
            foreach ($column->uses as $name) {
                $used[$name] = true;
            }
        }

        return array_keys($used);
    }
}
