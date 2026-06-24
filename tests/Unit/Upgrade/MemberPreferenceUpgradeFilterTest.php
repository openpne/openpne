<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Steps\MemberPreferenceUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade derives its source-name set from PreferenceKey::upgradableCases(), so an
 * OpenPNE 4-native key (null op3SourceName, e.g. preferred_surface) must never leak an empty
 * name into the WHERE ... IN (...) — which would migrate stray rows or match nothing oddly.
 */
class MemberPreferenceUpgradeFilterTest extends TestCase
{
    public function test_filter_lists_only_op3_source_names_and_no_empty_name(): void
    {
        $filter = (new MemberPreferenceUpgrade)->filter();

        $this->assertNotNull($filter);
        $this->assertStringNotContainsString("''", $filter);
        $this->assertStringContainsString("'diary_public_flag'", $filter);
        $this->assertStringContainsString("'age_public_flag'", $filter);
        $this->assertStringNotContainsString('preferred_surface', $filter);
    }
}
