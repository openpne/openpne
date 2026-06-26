<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use Tests\TestCase;

/**
 * Pins the canonical key order for lang/{ja,en}.json: ASCII case-insensitive
 * with an uppercase-first tiebreak, lexicographic (not numeric-aware). The
 * `i18n:check` gate uses the same comparator, so this also documents what
 * `i18n:check --sort` produces.
 */
class I18nKeyOrderTest extends TestCase
{
    public function test_compare_is_case_insensitive_with_uppercase_first_tiebreak(): void
    {
        // Case-only variants get a deterministic position (uppercase first).
        $this->assertLessThan(0, Cmd::localeKeyCompare('Cancel', 'cancel'));

        // Variants of the same word neighbour, regardless of case.
        $keys = ['event', 'Email', 'email', 'Event'];
        usort($keys, [Cmd::class, 'localeKeyCompare']);
        $this->assertSame(['Email', 'email', 'Event', 'event'], $keys);

        // Lexicographic, not numeric-aware: "Page 10" precedes "Page 2".
        $this->assertLessThan(0, Cmd::localeKeyCompare('Page 10', 'Page 2'));
    }

    public function test_dictionaries_are_in_canonical_key_order(): void
    {
        foreach (['ja', 'en'] as $lang) {
            $path = base_path("lang/{$lang}.json");
            $keys = array_map('strval', array_keys((array) json_decode((string) file_get_contents($path), true)));
            $sorted = $keys;
            usort($sorted, [Cmd::class, 'localeKeyCompare']);

            $this->assertSame($sorted, $keys, "lang/{$lang}.json keys must be in canonical order (run `php artisan i18n:check --sort`)");
        }
    }
}
