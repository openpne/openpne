<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use App\Notifications\Settings\NotificationKind;
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

        // Every kind is wired today, so the rule is stated against the registry rather than against
        // one dormant kind: the "out" half is vacuous now and re-arms the moment an unwired kind is
        // registered, which is when it matters.
        foreach (NotificationKind::cases() as $kind) {
            $kind->definition()->isWired
                ? $this->assertArrayHasKey($kind->definition()->caption, $sources)
                : $this->assertArrayNotHasKey($kind->definition()->caption, $sources);
        }

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
