<?php

namespace Tests\Unit\Features\Timeline;

use App\Features\Timeline\HashtagParser;
use PHPUnit\Framework\TestCase;

/**
 * The stored `tag` is the normalized lookup key; `offset`/`length` are code-point ranges over the raw
 * body, the same unit `timeline_post_mentions` uses.
 */
class HashtagParserTest extends TestCase
{
    public function test_a_tag_after_a_space_is_found(): void
    {
        $this->assertSame(
            [['tag' => 'tag', 'offset' => 3, 'length' => 4]],
            HashtagParser::parse('hi #tag there'),
        );
    }

    public function test_a_tag_at_the_start_of_the_body_is_found(): void
    {
        $this->assertSame(
            [['tag' => 'tag', 'offset' => 0, 'length' => 4]],
            HashtagParser::parse('#tag opens it'),
        );
    }

    public function test_a_tag_at_the_start_of_a_line_is_found(): void
    {
        $this->assertSame(
            [['tag' => 'tag', 'offset' => 6, 'length' => 4]],
            HashtagParser::parse("line1\n#tag"),
        );
    }

    public function test_a_tag_after_an_ideographic_space_is_found(): void
    {
        $this->assertSame(
            [['tag' => 'ごはん', 'offset' => 4, 'length' => 4]],
            HashtagParser::parse("今日は\u{3000}#ごはん"),
        );
    }

    public function test_a_marker_glued_to_a_word_is_not_a_tag(): void
    {
        $this->assertSame([], HashtagParser::parse('abc#tag'));
    }

    public function test_a_second_marker_glued_to_a_tag_is_not_a_tag(): void
    {
        $this->assertSame(
            [['tag' => 'tag', 'offset' => 0, 'length' => 4]],
            HashtagParser::parse('#tag#nope'),
        );
    }

    public function test_a_full_width_marker_opens_a_tag(): void
    {
        $this->assertSame(
            [['tag' => '全角', 'offset' => 0, 'length' => 3]],
            HashtagParser::parse("\u{FF03}全角"),
        );
    }

    public function test_a_tag_of_digits_only_is_not_a_tag(): void
    {
        $this->assertSame([], HashtagParser::parse('happy #2026'));
    }

    public function test_a_tag_of_full_width_digits_only_is_not_a_tag(): void
    {
        $this->assertSame([], HashtagParser::parse("happy #\u{FF12}\u{FF10}\u{FF12}\u{FF16}"));
    }

    public function test_digits_after_a_letter_stay_a_tag(): void
    {
        $this->assertSame(
            [['tag' => 'op4', 'offset' => 0, 'length' => 4]],
            HashtagParser::parse('#op4'),
        );
    }

    public function test_a_run_of_thirty_characters_is_a_tag(): void
    {
        $run = str_repeat('a', 30);

        $this->assertSame(
            [['tag' => $run, 'offset' => 0, 'length' => 31]],
            HashtagParser::parse('#'.$run),
        );
    }

    public function test_a_run_of_thirty_one_characters_yields_no_tag_at_all(): void
    {
        // Not "the first 30 of it": an over-long run is one word the author wrote, and half of it
        // is a tag nobody meant.
        $this->assertSame([], HashtagParser::parse('#'.str_repeat('a', 31)));
    }

    public function test_a_run_that_only_overruns_the_cap_once_normalized_is_not_a_tag(): void
    {
        // U+FB01 is one code point that NFKC expands to two, so 30 of them become 60.
        $this->assertSame([], HashtagParser::parse('#'.str_repeat("\u{FB01}", 30)));
    }

    public function test_a_long_vowel_mark_is_part_of_a_tag(): void
    {
        $this->assertSame(
            [['tag' => 'ラーメン', 'offset' => 0, 'length' => 5]],
            HashtagParser::parse('#ラーメン'),
        );
    }

    public function test_a_combining_mark_is_part_of_a_tag(): void
    {
        // か + U+3099 (combining voiced sound mark), which NFKC composes into が.
        $this->assertSame(
            [['tag' => 'が', 'offset' => 0, 'length' => 3]],
            HashtagParser::parse("#か\u{3099}"),
        );
    }

    public function test_an_astral_emoji_before_a_tag_does_not_shift_its_offset(): void
    {
        // U+1F600 is one code point but four bytes, and preg reports bytes.
        $this->assertSame(
            [['tag' => 'tag', 'offset' => 2, 'length' => 4]],
            HashtagParser::parse("\u{1F600} #tag"),
        );
    }

    public function test_offsets_stay_in_code_points_across_several_emoji(): void
    {
        $this->assertSame(
            [
                ['tag' => 'a', 'offset' => 2, 'length' => 2],
                ['tag' => 'b', 'offset' => 7, 'length' => 2],
            ],
            HashtagParser::parse("\u{1F600} #a \u{1F600} #b"),
        );
    }

    public function test_case_and_full_width_forms_normalize_to_one_tag(): void
    {
        $tags = HashtagParser::parse("#TAG #tag #\u{FF34}\u{FF21}\u{FF27}");

        $this->assertSame(['tag', 'tag', 'tag'], array_column($tags, 'tag'));
        // The ranges stay over the raw body, so each one still cuts out what was typed.
        $this->assertSame([0, 5, 10], array_column($tags, 'offset'));
        $this->assertSame([4, 4, 4], array_column($tags, 'length'));
    }

    public function test_half_width_kana_normalizes_to_full_width(): void
    {
        $this->assertSame(
            [['tag' => 'ガ', 'offset' => 0, 'length' => 3]],
            HashtagParser::parse("#\u{FF76}\u{FF9E}"),
        );
    }

    public function test_the_same_tag_twice_is_two_rows(): void
    {
        $this->assertSame(
            [
                ['tag' => 'ok', 'offset' => 0, 'length' => 3],
                ['tag' => 'ok', 'offset' => 4, 'length' => 3],
            ],
            HashtagParser::parse('#ok #ok'),
        );
    }

    public function test_at_most_ten_tags_are_kept_in_body_order(): void
    {
        $body = implode(' ', array_map(fn (int $i): string => '#t'.$i, range(0, 11)));

        $tags = HashtagParser::parse($body);

        $this->assertSame(
            ['t0', 't1', 't2', 't3', 't4', 't5', 't6', 't7', 't8', 't9'],
            array_column($tags, 'tag'),
        );
    }

    public function test_a_tag_inside_a_mention_is_skipped(): void
    {
        // A member whose display name carries a marker: "@dev #ops" is the handle, not a topic.
        $body = 'hi @dev #ops #ok';

        $tags = HashtagParser::parse($body, [['offset' => 3, 'length' => 9]]);

        $this->assertSame([['tag' => 'ok', 'offset' => 13, 'length' => 3]], $tags);
    }

    public function test_a_tag_touching_the_end_of_a_mention_is_kept(): void
    {
        // The mention's range is half-open, so [3, 7) and [8, 12) do not meet.
        $body = 'hi @dev #ops';

        $tags = HashtagParser::parse($body, [['offset' => 3, 'length' => 4]]);

        $this->assertSame([['tag' => 'ops', 'offset' => 8, 'length' => 4]], $tags);
    }

    public function test_a_body_with_no_marker_yields_nothing(): void
    {
        $this->assertSame([], HashtagParser::parse('nothing to see here'));
    }

    public function test_a_bare_marker_is_not_a_tag(): void
    {
        $this->assertSame([], HashtagParser::parse('# # #'));
    }
}
