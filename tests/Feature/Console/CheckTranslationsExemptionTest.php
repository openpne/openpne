<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CheckTranslationsCommand;
use Tests\TestCase;

/**
 * The `i18n:check` exemption for pure-placeholder keys must validate that
 * every placeholder resolves to a configured term — otherwise a typo like
 * `__('%Firend%')` slips past the coverage gate and renders raw at runtime
 * (the term service intentionally leaves unknown placeholders untouched).
 */
class CheckTranslationsExemptionTest extends TestCase
{
    private const KNOWN_TERMS = ['friend', 'community'];

    public function test_known_lowercase_placeholder_is_exempt(): void
    {
        $this->assertTrue(CheckTranslationsCommand::isResolvableViaTermLayer('%friend%', self::KNOWN_TERMS));
    }

    public function test_known_fronted_placeholder_is_exempt(): void
    {
        $this->assertTrue(CheckTranslationsCommand::isResolvableViaTermLayer('%Friend%', self::KNOWN_TERMS));
    }

    public function test_known_plural_placeholder_is_exempt(): void
    {
        $this->assertTrue(CheckTranslationsCommand::isResolvableViaTermLayer('%communities%', self::KNOWN_TERMS));
        $this->assertTrue(CheckTranslationsCommand::isResolvableViaTermLayer('%Communities%', self::KNOWN_TERMS));
    }

    public function test_multiple_known_placeholders_separated_by_whitespace_are_exempt(): void
    {
        $this->assertTrue(CheckTranslationsCommand::isResolvableViaTermLayer('%Friend% %Community%', self::KNOWN_TERMS));
    }

    public function test_typo_placeholder_is_not_exempt(): void
    {
        // Regression: a typo'd placeholder name renders raw at runtime, so
        // the coverage gate must require a real translation rather than
        // exempting it on shape alone.
        $this->assertFalse(CheckTranslationsCommand::isResolvableViaTermLayer('%Firend%', self::KNOWN_TERMS));
    }

    public function test_mix_of_known_and_unknown_is_not_exempt(): void
    {
        $this->assertFalse(CheckTranslationsCommand::isResolvableViaTermLayer('%Friend% %Firend%', self::KNOWN_TERMS));
    }

    public function test_key_with_surrounding_text_is_not_exempt(): void
    {
        $this->assertFalse(CheckTranslationsCommand::isResolvableViaTermLayer('Hello %Friend%', self::KNOWN_TERMS));
    }

    public function test_empty_or_whitespace_only_keys_are_not_exempt(): void
    {
        $this->assertFalse(CheckTranslationsCommand::isResolvableViaTermLayer('', self::KNOWN_TERMS));
        $this->assertFalse(CheckTranslationsCommand::isResolvableViaTermLayer('   ', self::KNOWN_TERMS));
    }
}
