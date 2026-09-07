<?php

namespace Tests\Unit\Timeline;

use App\Features\Timeline\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://sns.example/diary/12';

    public function test_the_line_and_the_url_are_joined_by_a_newline(): void
    {
        $announcement = app(Announcement::class);

        $this->assertSame("[Diary] Hello\n".self::URL, $announcement->diary('Hello', self::URL, 'en'));
        $this->assertSame("[日記] こんにちは\n".self::URL, $announcement->diary('こんにちは', self::URL, 'ja'));
        $this->assertSame("[Group topic] Marathon (Runners)\n".self::URL, $announcement->topic('Runners', 'Marathon', self::URL, 'en'));
        $this->assertSame("[Group event] Picnic (Runners, 2026年06月04日 13:00)\n".self::URL, $announcement->event('Runners', 'Picnic', '2026年06月04日 13:00', self::URL, 'en'));
        $this->assertSame("[Group event] Picnic (Runners)\n".self::URL, $announcement->event('Runners', 'Picnic', '', self::URL, 'en'));
    }

    public function test_a_title_spelling_a_term_placeholder_stays_text(): void
    {
        // Terms are settled before the member's words go in, so "%Diary%" in a title is not a placeholder.
        $announcement = app(Announcement::class);

        $this->assertSame("[Diary] about %Diary% and %community%\n".self::URL, $announcement->diary('about %Diary% and %community%', self::URL, 'en'));
        $this->assertSame("[Group topic] t (%Community%)\n".self::URL, $announcement->topic('%Community%', 't', self::URL, 'en'));
    }

    public function test_openpne3_emoji_codes_in_the_words_render_as_emoji(): void
    {
        $this->assertSame("[Diary] hi \u{2600}\u{FE0F}\n".self::URL, app(Announcement::class)->diary('hi [i:1]', self::URL, 'en'));
    }

    public function test_the_line_gives_way_to_fit_and_the_url_is_never_cut(): void
    {
        $announcement = app(Announcement::class);
        $url = 'https://sns.example/'.str_repeat('u', 100); // 120 code points

        $body = $announcement->compose(str_repeat('あ', 50), $url);

        $this->assertSame(140, mb_strlen($body));
        $this->assertStringEndsWith("…\n".$url, $body);
        $this->assertSame(str_repeat('あ', 18).'…', explode("\n", $body)[0]); // 140 - 120 - 1 = 19, one of them the ellipsis
    }

    public function test_a_line_that_fits_is_kept_whole_at_the_boundary(): void
    {
        $announcement = app(Announcement::class);
        $url = 'https://sns.example/'.str_repeat('u', 100);

        $this->assertSame(str_repeat('a', 19)."\n".$url, $announcement->compose(str_repeat('a', 19), $url));
        // Code points, not bytes: 19 four-byte emoji still fit.
        $this->assertSame(str_repeat("\u{1F600}", 19)."\n".$url, $announcement->compose(str_repeat("\u{1F600}", 19), $url));
    }

    public function test_a_url_that_leaves_no_room_stands_alone_and_one_that_does_not_fit_means_no_post(): void
    {
        $announcement = app(Announcement::class);

        $this->assertSame(str_repeat('u', 140), $announcement->compose('x', str_repeat('u', 140)));
        $this->assertSame(str_repeat('u', 139), $announcement->compose('x', str_repeat('u', 139)));
        $this->assertNull($announcement->compose('x', str_repeat('u', 141)));
    }
}
