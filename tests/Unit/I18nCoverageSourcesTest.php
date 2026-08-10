<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use App\Services\TermService;
use Tests\TestCase;

/**
 * Pins the surfaced-only coverage corpus: only registry captions/help that actually render (wired
 * kinds, categories with a wired kind, all mail-template strings) are gated for a ja translation, so
 * an unwired kind stays out until it becomes wired.
 */
class I18nCoverageSourcesTest extends TestCase
{
    public function test_coverage_sources_are_surfaced_only(): void
    {
        $sources = Cmd::coverageSourceStrings();

        // Surfaced → gated: a wired kind caption, mail-template help, a wired category's heading.
        $this->assertArrayHasKey('New comments on %topics% in your %communities%', $sources);
        $this->assertArrayHasKey('The %community% name.', $sources);
        $this->assertArrayHasKey('%Diaries%', $sources);

        // Unsurfaced → out: an unwired kind's caption.
        $this->assertArrayNotHasKey('New %activity% posts (everyone)', $sources);
        // Its category is in all the same, because a sibling kind of it is wired — the corpus
        // follows what renders. (No all-unwired category is left to assert the other way.)
        $this->assertArrayHasKey('%Activity%', $sources);
    }

    public function test_every_surfaced_source_has_ja_or_is_pure_placeholder(): void
    {
        $ja = json_decode((string) file_get_contents(base_path('lang/ja.json')), true);
        $termNames = array_keys(TermService::defaults('ja'));

        foreach (array_keys(Cmd::coverageSourceStrings()) as $source) {
            if (Cmd::isResolvableViaTermLayer($source, $termNames)) {
                continue; // pure-placeholder resolves via the term layer; a ja entry is optional
            }
            $this->assertArrayHasKey($source, $ja, "Surfaced registry string lacks a ja translation: {$source}");
        }
    }
}
