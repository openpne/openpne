<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use Tests\TestCase;

/**
 * Pins the namespace-boundary helpers behind `i18n:check`: a flat JSON key may
 * not fall under a PHP group (laravel-react-i18n merges php_{locale}.json last,
 * so PHP silently wins), every PHP group must be classified, and app-ui groups
 * need symmetric ja/en coverage.
 */
class I18nNamespaceBoundaryTest extends TestCase
{
    public function test_json_keys_under_php_groups_flags_only_group_prefixed_keys(): void
    {
        $groups = ['terms', 'validation', 'pagination'];

        // Exact dotted match, deeper match, and the bare group name all collide.
        $this->assertSame(
            ['terms.friend', 'validation.required.x', 'terms'],
            Cmd::jsonKeysUnderPhpGroups(['terms.friend', 'validation.required.x', 'terms'], $groups),
        );
    }

    public function test_json_keys_under_php_groups_ignores_sentences_and_plain_keys(): void
    {
        $groups = ['terms', 'validation'];

        // Sentence keys whose first dot-segment is not a group, and dot-free
        // labels, are not collisions.
        $this->assertSame(
            [],
            Cmd::jsonKeysUnderPhpGroups(
                ['%Community% deleted.', 'Save', 'Showing', 'No results found.'],
                $groups,
            ),
        );
    }

    public function test_unknown_groups_returns_groups_outside_the_classification(): void
    {
        $known = ['validation', 'terms', 'regions'];

        $this->assertSame(['buttons'], Cmd::unknownGroups(['validation', 'terms', 'buttons'], $known));
        $this->assertSame([], Cmd::unknownGroups(['validation', 'terms'], $known));
    }

    public function test_coverage_gaps_reports_one_sided_keys_each_way(): void
    {
        $this->assertSame(
            ['missing_en' => ['terms.topic'], 'missing_ja' => ['terms.extra']],
            Cmd::coverageGaps(['terms.friend', 'terms.topic'], ['terms.friend', 'terms.extra']),
        );

        $this->assertSame(
            ['missing_en' => [], 'missing_ja' => []],
            Cmd::coverageGaps(['terms.friend'], ['terms.friend']),
        );
    }
}
