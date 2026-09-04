<?php

declare(strict_types=1);

namespace Tests\Feature\Translation;

use App\Translation\TermTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class TermTranslatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_translator_binding_swaps_in_term_translator(): void
    {
        $this->assertInstanceOf(TermTranslator::class, $this->app['translator']);
    }

    public function test_fallback_locale_is_inherited_from_framework_translator(): void
    {
        // Extending the `translator` binding must carry over the fallback locale the base instance was
        // given, or untranslated keys stop resolving.
        $this->assertSame(config('app.fallback_locale'), $this->app['translator']->getFallback());
    }

    public function test_unknown_keys_resolve_through_the_fallback_locale(): void
    {
        // Place a fallback-only translation file and verify __() returns the
        // fallback value when the active locale lacks the key.
        $fallback = config('app.fallback_locale');
        $this->app->setLocale($fallback === 'en' ? 'ja' : 'en');

        Lang::addLines(['_test.fallback_only' => 'Only in fallback'], $fallback);

        $this->assertSame('Only in fallback', __('_test.fallback_only'));
    }

    public function test_translator_replaces_placeholders_in_resolved_strings(): void
    {
        Lang::addLines(['_test.term_line' => '%Friend% list'], 'en');

        $this->app->setLocale('en');

        $this->assertSame('Friend list', __('_test.term_line'));
    }

    public function test_has_returns_false_for_unregistered_placeholder_key(): void
    {
        // `Translator::has()` is `get(...) !== $key`, so without the override the placeholder
        // substitution would make an unregistered `%Friend%` look present.
        $this->assertFalse(Lang::has('%Friend%', 'ja'));
    }

    public function test_pure_placeholder_key_renders_via_substitution_when_unregistered(): void
    {
        // The pure-placeholder exemption in `i18n:check` depends on `__()` resolving `%Friend%` while
        // `Lang::has` reports it absent.
        $this->app->setLocale('ja');

        $this->assertSame('フレンド', __('%Friend%'));
    }
}
