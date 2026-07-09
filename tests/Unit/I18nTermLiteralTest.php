<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use Tests\TestCase;

/**
 * Pins the term-literal gate behind `i18n:check`: term-vocabulary words must live inside a
 * `%term%` placeholder, never as a bare literal, so an admin's term override reaches every surface.
 * Covers the pure detector and the dynamic-registry corpus (captions/help that reach __() via a
 * variable, so the code scanner never sees them).
 */
class I18nTermLiteralTest extends TestCase
{
    public function test_bare_english_term_words_are_flagged(): void
    {
        $this->assertSame(['diaries'], Cmd::bareTermMatches('Latest diaries', Cmd::TERM_EN_WORDS, true));
        $this->assertSame(['friends'], Cmd::bareTermMatches("A list of the member's friends.", Cmd::TERM_EN_WORDS, true));
        $this->assertSame(['communities'], Cmd::bareTermMatches('Members can create communities in this category', Cmd::TERM_EN_WORDS, true));
        // A source-only registry caption (no ja entry) — the exact class the gate would otherwise miss.
        $this->assertSame(['timeline'], Cmd::bareTermMatches('New timeline posts (everyone)', Cmd::TERM_EN_WORDS, true));
    }

    public function test_placeholder_wrapped_terms_are_not_flagged(): void
    {
        $this->assertSame([], Cmd::bareTermMatches('Next %Diary%', Cmd::TERM_EN_WORDS, true));
        $this->assertSame([], Cmd::bareTermMatches('New %activity% posts (everyone)', Cmd::TERM_EN_WORDS, true));
        $this->assertSame([], Cmd::bareTermMatches('The %friend% management URL.', Cmd::TERM_EN_WORDS, true));
    }

    public function test_param_tokens_are_not_flagged(): void
    {
        // `:community` is a Laravel replacement parameter, not the term word.
        $this->assertSame([], Cmd::bareTermMatches(':count join requests for :community', Cmd::TERM_EN_WORDS, true));
        $this->assertSame([], Cmd::bareTermMatches('A %topic% can have at most :max images.', Cmd::TERM_EN_WORDS, true));
    }

    public function test_clean_strings_return_empty(): void
    {
        $this->assertSame([], Cmd::bareTermMatches('Invite a new member', Cmd::TERM_EN_WORDS, true));
        $this->assertSame([], Cmd::bareTermMatches('Send invitation', Cmd::TERM_EN_WORDS, true));
    }

    public function test_japanese_term_words_matched_as_substring(): void
    {
        $this->assertSame(['コミュニティ'], Cmd::bareTermMatches('メンバーはコミュニティに参加できます', Cmd::TERM_JA_WORDS, false));
        // Placeholders strip out before matching, so a term-ized ja value is clean.
        $this->assertSame([], Cmd::bareTermMatches('%diary%コメント', Cmd::TERM_JA_WORDS, false));
        $this->assertSame([], Cmd::bareTermMatches('新しい%diaries%の初期公開範囲', Cmd::TERM_JA_WORDS, false));
    }

    /**
     * Regression (Codex plan review): dynamic-registry captions/help reach __() via a variable and
     * never enter the code scanner, so an unwired, ja-unregistered caption like the old
     * "New timeline posts (everyone)" could stay literal while `i18n:check` reported green. The gate
     * scans them via {@see Cmd::dynamicSourceStrings()} — assert the source-only unwired caption is
     * in that corpus and that every collected source is term-clean.
     */
    public function test_dynamic_registry_sources_are_collected_and_term_clean(): void
    {
        $sources = Cmd::dynamicSourceStrings();

        $this->assertArrayHasKey('New %activity% posts (everyone)', $sources, 'unwired registry captions must be gated');

        foreach (array_keys($sources) as $source) {
            $this->assertSame(
                [],
                Cmd::bareTermMatches($source, Cmd::TERM_EN_WORDS, true),
                "Registry source string carries a bare term literal (wrap it in a %term%): {$source}",
            );
        }
    }
}
