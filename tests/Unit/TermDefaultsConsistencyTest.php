<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\SetLocale;
use App\Services\TermService;
use Tests\TestCase;

/**
 * The Filament admin form renders one row per term name. Term names that exist
 * in one locale but not another would either be hidden from the form or fall
 * back silently — both confusing. Lock the key set to match across locales.
 */
class TermDefaultsConsistencyTest extends TestCase
{
    public function test_every_supported_locale_defines_the_same_term_names(): void
    {
        $reference = array_keys(TermService::defaults(SetLocale::SUPPORTED_LOCALES[0]));
        sort($reference);

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $keys = array_keys(TermService::defaults($locale));
            sort($keys);

            $this->assertSame(
                $reference,
                $keys,
                "lang/{$locale}/terms.php must define the same term names as lang/".SetLocale::SUPPORTED_LOCALES[0].'/terms.php',
            );
        }
    }

    public function test_default_values_are_non_empty_strings(): void
    {
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            foreach (TermService::defaults($locale) as $name => $value) {
                $this->assertIsString($value, "lang/{$locale}/terms.php['{$name}'] must be a string");
                $this->assertNotSame('', $value, "lang/{$locale}/terms.php['{$name}'] must not be empty");
            }
        }
    }
}
