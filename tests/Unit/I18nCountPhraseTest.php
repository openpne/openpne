<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every `:count` key in lang/ja.json has a `1 …` sibling whose ja reads as the plural does at one,
 * unless EXEMPT says why the plural stands at one; that a screen picks the sibling is the ESLint
 * rule on resources/js and, for `__()` in app/ and resources/views, the call-site scan here.
 */
class I18nCountPhraseTest extends TestCase
{
    /** @var array<string, string|null> key => the singular it does have in another shape, or null for none */
    private const EXEMPT = [
        // The same words at one, or a count beside a heading.
        'and :count more' => null,
        ':count in use' => null,
        ':count selected' => null,
        'Digit :number of :count' => null,
        '%Friends% (:count)' => null,
        'Joined %communities% (:count)' => null,
        // Read by the framework, not the app: the reset mail's expiry is config (60), and the validation
        // summary carries its own singular.
        'This password reset link will expire in :count minutes.' => null,
        '(and :count more error)' => null,
        '(and :count more errors)' => '(and :count more error)',
        // Relative time says it with an article.
        ':count minutes ago' => 'A minute ago',
        ':count hours ago' => 'An hour ago',
        ':count days ago' => 'A day ago',
        ':count months ago' => 'A month ago',
        ':count years ago' => 'A year ago',
        // Never one: the card exists from TalkAbsenceDigest::THRESHOLD messages up.
        ':count messages while you were away' => null,
        // Classic keeps its OpenPNE 3 wording.
        'View :count per page' => null,
        'There are new :count messages!' => null,
        "You've gotten :count %community% joining requests" => null,
        "You've gotten :count %friend% requests" => null,
    ];

    public function test_every_count_key_has_the_singular_the_surface_picks_at_one(): void
    {
        $ja = $this->ja();

        foreach (array_keys($ja) as $key) {
            if (! str_contains($key, ':count')) {
                continue;
            }

            if (array_key_exists($key, self::EXEMPT)) {
                if (self::EXEMPT[$key] !== null) {
                    $this->assertArrayHasKey(self::EXEMPT[$key], $ja, "`{$key}` is exempt because `".self::EXEMPT[$key].'` is its singular, but that key is gone');
                }

                continue;
            }

            $singular = self::singularOf($key);
            $this->assertTrue($singular !== null && array_key_exists($singular, $ja),
                "`{$key}` has no singular key".($singular === null ? '' : " `{$singular}`").': add it and pick it at one (resources/js/lib/count-phrase.ts), or list the key in EXEMPT with why the plural stands at one');

            // Japanese has no plural, so the two keys must read the same at one — else fixing one
            // wording leaves the other behind for exactly one.
            $this->assertSame(
                self::withSingularTerms(str_replace(':count', '1', $ja[$key])),
                self::withSingularTerms($ja[$singular]),
                "`{$singular}` reads differently in ja from `{$key}` at one",
            );
        }
    }

    public function test_every_plural_count_call_in_php_or_blade_is_one_arm_of_a_one_ternary(): void
    {
        $finder = Finder::create()->files()->in([base_path('app'), base_path('resources/views')])->name('*.php');

        foreach ($finder as $file) {
            $contents = $file->getContents();
            preg_match_all('/(?:__|trans|@lang)\(\s*([\'"])((?:(?!\1).)*?:count (?:(?!\1).)*)\1/', $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($matches as $match) {
                [$call, $offset] = $match[0];
                $key = $match[2][0];
                if (! preg_match('/(^|\s):count .*s\b/', $key) || array_key_exists($key, self::EXEMPT)) {
                    continue;
                }

                // The statement the call sits in: back to the last `;`, `{` or `}` (a Blade echo opens with `{{`).
                $start = max(array_map(static fn (string $stop): int => (int) strrpos(substr($contents, 0, $offset), $stop), [';', '{', '}']));
                $this->assertStringContainsString('=== 1', substr($contents, $start, $offset - $start),
                    "{$file->getRelativePathname()}: `{$key}` is read at one as a plural: make it one arm of a `=== 1` ternary beside its `1 …` key, or list the key in EXEMPT with why the plural stands at one");
            }
        }
    }

    public function test_no_exemption_is_stale(): void
    {
        $ja = $this->ja();

        foreach (array_keys(self::EXEMPT) as $key) {
            $this->assertArrayHasKey($key, $ja, "EXEMPT lists `{$key}`, which lang/ja.json no longer has");
        }
    }

    public function test_the_singular_replaces_the_count_and_the_first_plural_word_after_it(): void
    {
        $this->assertSame('1 comment', self::singularOf(':count comments'));
        $this->assertSame('1 entry', self::singularOf(':count entries'));
        $this->assertSame('Jump to 1 unread message', self::singularOf('Jump to :count unread messages'));
        $this->assertSame('1 pending %friend% request', self::singularOf(':count pending %friend% requests'));
        $this->assertSame('1 %community% with new messages', self::singularOf(':count %communities% with new messages'));
        $this->assertSame('1 join request for :community', self::singularOf(':count join requests for :community'));
        $this->assertSame('In 1 %community%', self::singularOf('In :count %communities%'));
    }

    private static function singularOf(string $key): ?string
    {
        $words = explode(' ', $key);
        $at = array_search(':count', $words, true);
        if ($at === false) {
            return null;
        }
        $words[$at] = '1';

        for ($i = $at + 1, $n = count($words); $i < $n; $i++) {
            $bare = trim($words[$i], '%');
            if ($bare === '' || str_starts_with($words[$i], ':')) {
                continue;
            }
            $singular = Str::singular($bare);
            if ($singular !== $bare) {
                $words[$i] = str_replace($bare, $singular, $words[$i]);
                break;
            }
        }

        return implode(' ', $words);
    }

    private static function withSingularTerms(string $text): string
    {
        return (string) preg_replace_callback('/%([a-z]+)%/i', static fn (array $m): string => '%'.Str::singular($m[1]).'%', $text);
    }

    /** @return array<string, string> */
    private function ja(): array
    {
        return json_decode((string) file_get_contents(base_path('lang/ja.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
