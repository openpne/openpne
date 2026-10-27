<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

/**
 * A checkpointed PHP phase of the run (a post-walk pass or the file_bin migration), listed in the
 * run manifest under its class name and VERSION. VERSION is bumped when the phase's behaviour changes
 * (docs/internals/upgrade.md, "Checkpoints and resume").
 */
interface Phase
{
    /**
     * The checkpoint keys run() writes for these targets; the same applicability test run() uses, so
     * an empty list means the phase has nothing to do for this run and counts as complete.
     *
     * @param  list<string>  $targetTables  the run's step target tables
     * @param  list<string>  $sourceTables  the run's step source tables
     * @return list<string>
     */
    public function checkpointKeys(array $targetTables, array $sourceTables): array;

    /** The phase's VERSION constant, as the manifest records it. */
    public function version(): string;
}
