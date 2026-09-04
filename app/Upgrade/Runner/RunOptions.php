<?php

namespace App\Upgrade\Runner;

/**
 * Only the source is parameterised: the target is always the app connection's database at its empty
 * prefix, because the running app reads those tables. sourcePrefix / sourceDatabase are validated as
 * identifiers by the command.
 */
final class RunOptions
{
    public function __construct(
        public readonly string $sourcePrefix = '',
        public readonly ?string $sourceDatabase = null,
        public readonly bool $dryRun = false,
        public readonly bool $forceRestart = false,
    ) {}
}
