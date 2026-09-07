<?php

namespace App\Upgrade\Runner;

/**
 * The activity preflight's read-only verdict: errors are source rows a step would fail on mid-run,
 * warnings are the dispositions the routing rules apply silently otherwise.
 */
final class ActivityPreflightReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
