<?php

namespace App\Upgrade\Runner;

/**
 * The source preflight's read-only verdict. Errors (a missing required table, a partial plugin
 * group, or a missing consumed column) abort the run before anything is written; absentOptional are
 * the not-installed plugin tables to create empty so their steps no-op.
 */
final class SourcePreflightReport
{
    /**
     * @param  list<string>  $tableErrors  required tables missing / partial plugin groups
     * @param  list<string>  $columnErrors  consumed columns missing on a present table
     * @param  list<string>  $absentOptional  optional plugin tables to ensure-exists
     */
    public function __construct(
        public readonly array $tableErrors,
        public readonly array $columnErrors,
        public readonly array $absentOptional,
    ) {}

    public function hasErrors(): bool
    {
        return $this->tableErrors !== [] || $this->columnErrors !== [];
    }
}
