<?php

namespace Tests\Unit\Support;

use App\Support\BodyText;
use App\Support\EntityText;
use PHPUnit\Framework\TestCase;

/**
 * The shared cases here are mirrored one for one by resources/js/lib/entity-split.test.ts, so both
 * surfaces cut the same bodies at the same code points. Escaping is asserted only on this side —
 * the Modern renderer defers it to React — so the JS sibling pins the raw segment shape instead.
 */
class EntityTextTest extends TestCase
{
    public function test_an_ascii_mention_becomes_an_anchor_between_the_surrounding_text(): void
    {
        $html = (string) EntityText::render('hi @Alice there', [$this->mention(3, 6)]);

        $this->assertSame('hi <a href="/member/7" class="mention">@Alice</a> there', $html);
    }

    public function test_an_astral_emoji_before_the_mention_does_not_shift_the_range(): void
    {
        // U+1F600 is one code point but two UTF-16 units and four bytes.
        $html = (string) EntityText::render('😀 hi @Alice', [$this->mention(5, 6)]);

        $this->assertSame('😀 hi <a href="/member/7" class="mention">@Alice</a>', $html);
    }

    public function test_a_zwj_emoji_sequence_counts_as_its_code_points(): void
    {
        // One rendered glyph, five code points: man + ZWJ + woman + ZWJ + girl.
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        $html = (string) EntityText::render($family.' @Alice', [$this->mention(6, 6)]);

        $this->assertSame($family.' <a href="/member/7" class="mention">@Alice</a>', $html);
    }

    public function test_a_url_right_after_a_mention_is_still_linked(): void
    {
        $html = (string) EntityText::render('@Alice https://example.com/x', [$this->mention(0, 6)]);

        $this->assertSame(
            '<a href="/member/7" class="mention">@Alice</a> '
                .'<a href="https://example.com/x" target="_blank" rel="noopener noreferrer nofollow">https://example.com/x</a>',
            $html,
        );
    }

    public function test_a_mention_at_the_start_of_a_line_keeps_the_line_break_before_it(): void
    {
        $html = (string) EntityText::render("line1\n@Alice", [$this->mention(6, 6)]);

        $this->assertSame("line1<br />\n<a href=\"/member/7\" class=\"mention\">@Alice</a>", $html);
    }

    public function test_a_mention_at_the_end_of_the_body_renders_nothing_after_it(): void
    {
        $html = (string) EntityText::render('hi @Alice', [$this->mention(3, 6)]);

        $this->assertSame('hi <a href="/member/7" class="mention">@Alice</a>', $html);
    }

    public function test_two_adjacent_mentions_render_back_to_back(): void
    {
        $html = (string) EntityText::render('@Alice@Bob', [$this->mention(0, 6), $this->mention(6, 4, '/member/8')]);

        $this->assertSame(
            '<a href="/member/7" class="mention">@Alice</a><a href="/member/8" class="mention">@Bob</a>',
            $html,
        );
    }

    public function test_a_hashtag_becomes_an_anchor_to_its_tag_page(): void
    {
        $html = (string) EntityText::render('ship it #op4', [$this->tag(8, 4, '/timeline/tag/op4')]);

        $this->assertSame('ship it <a href="/timeline/tag/op4" class="hashtag">#op4</a>', $html);
    }

    public function test_a_mention_and_a_hashtag_in_one_body_are_both_linked(): void
    {
        $html = (string) EntityText::render('hi @Alice #op4 bye', [$this->mention(3, 6), $this->tag(10, 4, '/timeline/tag/op4')]);

        $this->assertSame(
            'hi <a href="/member/7" class="mention">@Alice</a> <a href="/timeline/tag/op4" class="hashtag">#op4</a> bye',
            $html,
        );
    }

    public function test_a_hashtag_and_a_mention_render_back_to_back(): void
    {
        $html = (string) EntityText::render('#op4@Alice', [$this->tag(0, 4, '/timeline/tag/op4'), $this->mention(4, 6)]);

        $this->assertSame(
            '<a href="/timeline/tag/op4" class="hashtag">#op4</a><a href="/member/7" class="mention">@Alice</a>',
            $html,
        );
    }

    public function test_a_full_width_marker_is_shown_as_typed_while_the_href_carries_the_normalized_tag(): void
    {
        // The range is over the raw body, so the reader sees ＃ and full-width text; the href is the
        // stored tag (NFKC + lowercase), percent-encoded; route()'s own output is not asserted here.
        $html = (string) EntityText::render('＃ＴＡＧ です', [$this->tag(0, 4, '/timeline/tag/tag')]);

        $this->assertSame('<a href="/timeline/tag/tag" class="hashtag">＃ＴＡＧ</a> です', $html);
    }

    public function test_an_astral_emoji_before_the_hashtag_does_not_shift_the_range(): void
    {
        $html = (string) EntityText::render('😀 #op4', [$this->tag(2, 4, '/timeline/tag/op4')]);

        $this->assertSame('😀 <a href="/timeline/tag/op4" class="hashtag">#op4</a>', $html);
    }

    public function test_a_percent_encoded_tag_href_survives_escaping(): void
    {
        $html = (string) EntityText::render('#タグ', [$this->tag(0, 3, '/timeline/tag/%E3%82%BF%E3%82%B0')]);

        $this->assertSame('<a href="/timeline/tag/%E3%82%BF%E3%82%B0" class="hashtag">#タグ</a>', $html);
    }

    public function test_a_display_name_containing_a_url_is_not_autolinked_inside_the_anchor(): void
    {
        // A member named "www.example.com": autolinking the range would nest an anchor in an anchor.
        $html = (string) EntityText::render('hi @www.example.com', [$this->mention(3, 16)]);

        $this->assertSame('hi <a href="/member/7" class="mention">@www.example.com</a>', $html);
    }

    public function test_a_display_name_with_markup_is_escaped(): void
    {
        $html = (string) EntityText::render('hi @<script>alert(1)</script>', [$this->mention(3, 26)]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('>@&lt;script&gt;alert(1)&lt;/script&gt;</a>', $html);
    }

    public function test_no_entities_renders_exactly_as_body_text(): void
    {
        $body = "see https://example.com/x\n<b>and</b> hi";

        $this->assertSame((string) BodyText::render($body), (string) EntityText::render($body, []));
        $this->assertSame('', (string) EntityText::render(null, []));
    }

    public function test_a_140_code_point_body_ending_in_a_mention(): void
    {
        $body = str_repeat('あ', 134).'@Alice';
        $this->assertSame(140, mb_strlen($body));

        $html = (string) EntityText::render($body, [$this->mention(134, 6)]);

        $this->assertSame(str_repeat('あ', 134).'<a href="/member/7" class="mention">@Alice</a>', $html);
    }

    /** @return array{offset: int, length: int, kind: string, href: string} */
    private function mention(int $offset, int $length, string $href = '/member/7'): array
    {
        return ['offset' => $offset, 'length' => $length, 'kind' => 'mention', 'href' => $href];
    }

    /** @return array{offset: int, length: int, kind: string, href: string} */
    private function tag(int $offset, int $length, string $href): array
    {
        return ['offset' => $offset, 'length' => $length, 'kind' => 'hashtag', 'href' => $href];
    }
}
