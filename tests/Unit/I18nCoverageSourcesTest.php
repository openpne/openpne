<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use App\Services\TermService;
use Tests\TestCase;

/**
 * Pins the surfaced-only coverage corpus behind `i18n:check`. Captions/help that reach __() via a
 * variable never enter the code scanner; the coverage gate feeds them in, but only the subset that
 * actually renders — wired kinds, categories with a wired kind, and every mail-template string. So a
 * kind flipped to isWired:true gains its ja-translation requirement at that moment, and an unwired
 * kind stays out (no speculative translation, no baseline debt).
 */
class I18nCoverageSourcesTest extends TestCase
{
    public function test_coverage_sources_are_surfaced_only(): void
    {
        $sources = Cmd::coverageSourceStrings();

        // Wired kind caption — the reported half-English bug — is gated.
        $this->assertArrayHasKey('New comments on %topics% in your %communities%', $sources);
        // Mail-template variable help surfaces in the admin editor — gated.
        $this->assertArrayHasKey('The %community% name.', $sources);
        // Diary has wired kinds, so its heading renders — gated.
        $this->assertArrayHasKey('%Diaries%', $sources);

        // Unwired timeline kind never renders — must stay out so no speculative ja is demanded.
        $this->assertArrayNotHasKey('New %activity% posts (everyone)', $sources);
        // Timeline has no wired kinds, so its heading never renders — must stay out too.
        $this->assertArrayNotHasKey('%Activity%', $sources);
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
