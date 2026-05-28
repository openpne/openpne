<?php

declare(strict_types=1);

namespace Tests\Feature\Translation;

use App\Services\TermService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TermServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_terms_returns_defaults_when_no_overrides_exist(): void
    {
        $terms = app(TermService::class)->getTerms('ja');

        $this->assertSame('フレンド', $terms['friend']);
        $this->assertSame('コミュニティ', $terms['community']);
    }

    public function test_override_replaces_default_for_one_locale(): void
    {
        DB::table('term_overrides')->insert([
            'name' => 'friend',
            'locale' => 'ja',
            'value' => 'ともだち',
        ]);

        $service = app(TermService::class);
        $service->clearCache();

        $this->assertSame('ともだち', $service->getTerms('ja')['friend']);
        $this->assertSame('friend', $service->getTerms('en')['friend'], 'en should still see the default');
    }

    public function test_replace_substitutes_lowercase_placeholder(): void
    {
        $output = app(TermService::class)->replace('%friend% list', 'ja');

        $this->assertSame('フレンド list', $output);
    }

    public function test_replace_capitalises_in_english_for_uppercase_placeholder(): void
    {
        $output = app(TermService::class)->replace('%Friend% request', 'en');

        $this->assertSame('Friend request', $output);
    }

    public function test_replace_pluralises_in_english(): void
    {
        $output = app(TermService::class)->replace('My %communities%', 'en');

        $this->assertSame('My communities', $output);
    }

    public function test_replace_handles_irregular_english_plural(): void
    {
        $output = app(TermService::class)->replace('%activities%', 'en');

        // singular default is `timeline`, plural derives to `timelines`.
        $this->assertSame('timelines', $output);
    }

    public function test_replace_leaves_unknown_placeholders_untouched(): void
    {
        $output = app(TermService::class)->replace('Hello %unknown_term%', 'ja');

        $this->assertSame('Hello %unknown_term%', $output);
    }

    public function test_japanese_keeps_singular_for_plural_placeholder(): void
    {
        $output = app(TermService::class)->replace('%Communities%', 'ja');

        // No fronting and no pluralisation in Japanese.
        $this->assertSame('コミュニティ', $output);
    }

    public function test_clear_cache_invalidates_resolved_lookup(): void
    {
        $service = app(TermService::class);
        $this->assertSame('フレンド', $service->getTerms('ja')['friend']);

        // Insert directly without clearing — the cached value must persist.
        DB::table('term_overrides')->insert([
            'name' => 'friend',
            'locale' => 'ja',
            'value' => 'ともだち',
        ]);
        $this->assertSame('フレンド', $service->getTerms('ja')['friend'], 'cache should still return the old value');

        $service->clearCache();
        $this->assertSame('ともだち', $service->getTerms('ja')['friend']);
    }

    public function test_get_terms_is_cached(): void
    {
        // Prime the cache.
        app(TermService::class)->getTerms('ja');

        $this->assertTrue(Cache::has('terms.ja'));
    }
}
