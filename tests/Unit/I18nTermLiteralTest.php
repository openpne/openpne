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
        $this->assertSame(['diaries'], Cmd::bareTermMatches('Latest diaries', Cmd::termLiteralWords('en'), true));
        $this->assertSame(['friends'], Cmd::bareTermMatches("A list of the member's friends.", Cmd::termLiteralWords('en'), true));
        $this->assertSame(['groups'], Cmd::bareTermMatches('Members can create groups in this category', Cmd::termLiteralWords('en'), true));
        // A source-only registry caption (no ja entry) — the exact class the gate would otherwise miss.
        $this->assertSame(['timeline'], Cmd::bareTermMatches('New timeline posts (everyone)', Cmd::termLiteralWords('en'), true));
    }

    public function test_placeholder_wrapped_terms_are_not_flagged(): void
    {
        $this->assertSame([], Cmd::bareTermMatches('Next %Diary%', Cmd::termLiteralWords('en'), true));
        $this->assertSame([], Cmd::bareTermMatches('New %activity% posts (everyone)', Cmd::termLiteralWords('en'), true));
        $this->assertSame([], Cmd::bareTermMatches('The %friend% management URL.', Cmd::termLiteralWords('en'), true));
    }

    public function test_param_tokens_are_not_flagged(): void
    {
        // `:community` is a Laravel replacement parameter, not the term word.
        $this->assertSame([], Cmd::bareTermMatches(':count join requests for :community', Cmd::termLiteralWords('en'), true));
        $this->assertSame([], Cmd::bareTermMatches('A %topic% can have at most :max images.', Cmd::termLiteralWords('en'), true));
    }

    public function test_clean_strings_return_empty(): void
    {
        $this->assertSame([], Cmd::bareTermMatches('Invite a new member', Cmd::termLiteralWords('en'), true));
        $this->assertSame([], Cmd::bareTermMatches('Send invitation', Cmd::termLiteralWords('en'), true));
    }

    public function test_japanese_term_words_matched_as_substring(): void
    {
        $this->assertSame(['グループ'], Cmd::bareTermMatches('メンバーはグループに参加できます', Cmd::termLiteralWords('ja'), false));
        // Placeholders strip out before matching, so a term-ized ja value is clean.
        $this->assertSame([], Cmd::bareTermMatches('%diary%コメント', Cmd::termLiteralWords('ja'), false));
        $this->assertSame([], Cmd::bareTermMatches('新しい%diaries%の初期公開範囲', Cmd::termLiteralWords('ja'), false));
    }

    public function test_word_list_is_derived_from_term_defaults(): void
    {
        $en = Cmd::termLiteralWords('en');
        // Derived from lang/en/terms.php values (+ plurals), so a new term extends the gate with no
        // second list to maintain.
        $this->assertContains('diary', $en);
        $this->assertContains('diaries', $en);
        $this->assertContains('timeline', $en); // activity's value
        // post_activity's value ("post") is too generic to gate — and the retained "%activity% posts"
        // phrasing must not trip.
        $this->assertNotContains('post', $en);
        $this->assertNotContains('posts', $en);

        $ja = Cmd::termLiteralWords('ja');
        $this->assertContains('日記', $ja); // no pluralisation on the ja side
        $this->assertNotContains('ポスト', $ja);
    }

    /**
     * Dynamic-registry captions/help reach __() through a variable and never enter the code
     * scanner, so an unwired, ja-unregistered caption like "New timeline posts (everyone)" could
     * stay literal while i18n:check reported green. The gate closes that hole via
     * {@see Cmd::dynamicSourceStrings()}; assert that corpus carries the source-only unwired caption
     * and that every collected source is term-clean.
     */
    public function test_dynamic_registry_sources_are_collected_and_term_clean(): void
    {
        $sources = Cmd::dynamicSourceStrings();

        $this->assertArrayHasKey('New %activity% posts (everyone)', $sources, 'unwired registry captions must be gated');

        foreach (array_keys($sources) as $source) {
            $this->assertSame(
                [],
                Cmd::bareTermMatches($source, Cmd::termLiteralWords('en'), true),
                "Registry source string carries a bare term literal (wrap it in a %term%): {$source}",
            );
        }
    }
}
