<?php

namespace App\Upgrade\Runner;

/**
 * The term preflight's read-only verdict: errors are source rows the term step's INSERT would fail
 * on, warnings the recognised rows it leaves out on purpose, each with what applies instead.
 */
final class TermOverridePreflightReport
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
