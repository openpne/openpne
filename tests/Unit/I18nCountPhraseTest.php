<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every `:count` key in lang/ja.json has the singular key the Modern surface picks at exactly one
 * (resources/js/lib/count-phrase.ts), unless EXEMPT says why one is never read from it.
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
        // Never one: an expiry in minutes, the Laravel validation summary's own singular.
        'This password reset link will expire in :count minutes.' => null,
        '(and :count more error)' => null,
        '(and :count more errors)' => '(and :count more error)',
        // Relative time says it with an article (lib/use-date-format.ts, the Classic timeago script).
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
        // Admin notifications.
        ':count members added.' => null,
        'Cleared the layout choice of :count members' => null,
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
                "`{$key}` has no singular key".($singular === null ? '' : " `{$singular}`").': add it and pick it at one (resources/js/lib/count-phrase.ts), or list the key in EXEMPT with why one is never read from it');
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

    /** `:count` becomes `1`, and the first plural word after it its singular (a `%term%` keeps its marks); null when `:count` is not a word of its own. */
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

    /** @return array<string, string> */
    private function ja(): array
    {
        return json_decode((string) file_get_contents(base_path('lang/ja.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
