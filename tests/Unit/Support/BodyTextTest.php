<?php

namespace Tests\Unit\Support;

use App\Support\BodyText;
use PHPUnit\Framework\TestCase;

class BodyTextTest extends TestCase
{
    public function test_plain_text_is_html_escaped(): void
    {
        $html = (string) BodyText::render('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_newlines_become_br(): void
    {
        $this->assertStringContainsString('a<br', (string) BodyText::render("a\nb"));
    }

    public function test_http_url_is_linked_with_safe_rel(): void
    {
        $html = (string) BodyText::render('see https://example.com/x here');

        $this->assertStringContainsString(
            '<a href="https://example.com/x" target="_blank" rel="noopener noreferrer nofollow">https://example.com/x</a>',
            $html,
        );
    }

    public function test_www_url_gets_an_http_scheme_in_the_href(): void
    {
        $html = (string) BodyText::render('go to www.example.com now');

        $this->assertStringContainsString('href="http://www.example.com"', $html);
        $this->assertStringContainsString('>www.example.com</a>', $html);
    }

    public function test_trailing_punctuation_stays_outside_the_link(): void
    {
        $html = (string) BodyText::render('visit https://example.com.');

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('</a>.', $html);
    }

    public function test_query_ampersand_is_escaped_in_output(): void
    {
        $html = (string) BodyText::render('https://example.com/?a=1&b=2');

        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
        $this->assertStringNotContainsString('&b=2"', $html); // a raw & would be unsafe
    }

    public function test_a_crafted_url_cannot_break_out_of_the_anchor(): void
    {
        // The match stops at the double quote, so the injection survives only as escaped text.
        $html = (string) BodyText::render('http://evil.com/"onmouseover="alert(1)');

        $this->assertStringContainsString('href="http://evil.com/"', $html);
        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        $this->assertStringContainsString('&quot;onmouseover=', $html);
    }

    public function test_non_http_schemes_are_not_linked(): void
    {
        $html = (string) BodyText::render('javascript:alert(1)');

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('javascript:alert(1)', $html); // plain escaped text
    }

    public function test_long_url_text_is_truncated_but_the_href_is_full(): void
    {
        $url = 'https://example.com/'.str_repeat('a', 80);

        $html = (string) BodyText::render($url);

        $this->assertStringContainsString('href="'.$url.'"', $html); // full href
        $this->assertStringContainsString('...</a>', $html);          // truncated visible text
    }

    public function test_null_renders_nothing(): void
    {
        $this->assertSame('', (string) BodyText::render(null));
    }

    public function test_excerpt_collapses_newlines_to_spaces(): void
    {
        $this->assertSame('a b c', BodyText::excerpt("a\nb\r\nc"));
    }

    public function test_excerpt_truncates_to_display_width_108_without_an_ellipsis(): void
    {
        $this->assertSame(str_repeat('a', 108), BodyText::excerpt(str_repeat('a', 200)));
    }

    public function test_excerpt_counts_full_width_characters_as_two(): void
    {
        // 60 full-width characters span display width 120; the excerpt keeps 54 (width 108).
        $this->assertSame(str_repeat('あ', 54), BodyText::excerpt(str_repeat('あ', 60)));
    }

    public function test_excerpt_of_null_is_empty(): void
    {
        $this->assertSame('', BodyText::excerpt(null));
    }

    public function test_excerpt_strips_decoration_tags(): void
    {
        $this->assertSame('Bold and plain', BodyText::excerpt('<op:b>Bold</op:b> and plain'));
    }

    public function test_excerpt_strips_entity_encoded_decoration_tags(): void
    {
        $this->assertSame('Bold', BodyText::excerpt('&lt;op:b&gt;Bold&lt;/op:b&gt;'));
    }

    public function test_excerpt_strips_decoration_tags_with_attributes(): void
    {
        $this->assertSame('Hi', BodyText::excerpt('<op:color color="red">Hi</op:color>'));
    }

    public function test_excerpt_leaves_non_decoration_tags_for_blade_to_escape(): void
    {
        // Only op:* tags are decoration; a literal <b> is kept and escaped at output time.
        $this->assertSame('<b>keep</b>', BodyText::excerpt('<b>keep</b>'));
    }
}
