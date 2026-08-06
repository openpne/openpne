<?php

namespace App\Upgrade\Runner;

/**
 * The mail-template preflight's read-only verdict. Errors are source states the translation step cannot
 * survive (a locale collision, a locale too wide for the column), so they abort before anything is
 * written; warnings are templates that migrate but will not render on OpenPNE 4.
 */
final class MailTemplatePreflightReport
{
    /**
     * @param  list<string>  $errors  the step would fail on these rows
     * @param  list<string>  $warnings  migrated as-is, but broken or inert after the cutover
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
