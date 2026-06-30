<?php

namespace App\Upgrade\Runner;

/**
 * The upgrade runner's inputs. Only the source is parameterised: the target is always the current
 * app connection's database at its configured (empty) prefix, because the running app reads those
 * tables. sourcePrefix / sourceDatabase are validated as identifiers by the command before this.
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
