<?php

namespace Tests\Feature\Upgrade\Term;

use App\Http\Middleware\SetLocale;
use App\Services\TermService;
use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\TermOverrideUpgrade;
use App\Upgrade\Steps\WithdrawnMemberTermUpgrade;
use Tests\TestCase;

/** Driver-agnostic: the name-for-name contract between the OpenPNE 3 seed and the OpenPNE 4 key set. */
class TermOverrideUpgradeTest extends TestCase
{
    public function test_every_source_name_is_a_term_key_in_every_locale(): void
    {
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $keys = array_keys(TermService::defaults($locale));
            foreach (TermOverrideUpgrade::SOURCE_NAMES as $name) {
                $this->assertContains($name, $keys, "lang/{$locale}/terms.php must keep the OpenPNE 3 term name `{$name}`");
            }
        }
    }

    public function test_the_withdrawn_member_term_is_a_key_in_every_locale_with_a_step_each(): void
    {
        $locales = [];
        foreach (StepRegistry::all() as $step) {
            if ($step instanceof WithdrawnMemberTermUpgrade) {
                $locales[] = $step->locale();
            }
        }

        $this->assertEqualsCanonicalizing(SetLocale::SUPPORTED_LOCALES, $locales);
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $this->assertArrayHasKey(WithdrawnMemberTermUpgrade::TERM, TermService::defaults($locale));
        }
    }

    public function test_the_step_reads_both_source_tables(): void
    {
        $this->assertEqualsCanonicalizing(['sns_term_translation', 'sns_term'], (new TermOverrideUpgrade)->readSourceTables());
    }
}
