<?php

namespace Tests\Unit\Support;

use App\Support\Op3Text;
use PHPUnit\Framework\TestCase;

/**
 * Golden matrix for the OpenPNE 3 rich-text port. Cases marked "OP3 case N" are ported from the
 * OpenPNE 3 widget unit test (opWidgetFormRichTextareaOpenPNETest); where OpenPNE 4's text-token
 * escaping diverges from that test's semi-escaped inputs, the delta is called out (see the Op3Text
 * class docblock).
 */
class Op3TextTest extends TestCase
{
    private function render(string $input): string
    {
        return (string) Op3Text::render($input);
    }

    public function test_golden_matrix(): void
    {
        $cases = [
            // Every tag, raw form.
            ['<op:b>x</op:b>', '<span class="op_b">x</span>', 'bold raw'],
            ['<op:u>x</op:u>', '<span class="op_u">x</span>', 'underline raw'],
            ['<op:s>x</op:s>', '<span class="op_s">x</span>', 'strike raw'],
            ['<op:i>x</op:i>', '<span class="op_i">x</span>', 'italic raw'],
            ['<op:large>x</op:large>', '<span class="op_large">x</span>', 'large raw'],
            ['<op:small>x</op:small>', '<span class="op_small">x</span>', 'small raw'],

            // Every tag, entity form (a stored body that was already escaped).
            ['&lt;op:b&gt;x&lt;/op:b&gt;', '<span class="op_b">x</span>', 'bold entity'],
            ['&lt;op:s&gt;x&lt;/op:s&gt;', '<span class="op_s">x</span>', 'strike entity (literal entity op tag)'],

            // OP3 case 1/2/3: strike, broken inner <op> (not op:\w+, stays escaped text), unclosed.
            ['<op:s>どーん</op:s>', '<span class="op_s">どーん</span>', 'OP3 case 1'],
            ['<op:s>どどー<op>ん</op:s>', '<span class="op_s">どどー&lt;op&gt;ん</span>', 'OP3 case 2'],
            ['<op:s>どどー', '<span class="op_s">どどー</span>', 'OP3 case 3 (auto-close)'],

            // OP3 case 4: broken tag at the top — the leading <op:a is escaped text.
            ['<op:a<op:i>', '&lt;op:a<span class="op_i"></span>', 'OP3 case 4'],

            // OP3 case 5: quotes in text are escaped here (delta), unlike the OP3 unit test.
            ['<op:i color="#333<op:i>">#333</op:i>', '&lt;op:i color=&quot;#333<span class="op_i">&quot;&gt;#333</span>', 'OP3 case 5 (quotes escaped)'],

            // OP3 case 6: op:font colour → inline style with trailing semicolon.
            ['<op:font color="#333333">#333</op:font>', '<span class="op_font" style="color:#333333;">#333</span>', 'OP3 case 6'],

            // OP3 case 7: an unknown op tag still becomes a class span.
            ['<op:tetetetetete0111111>', '<span class="op_tetetetetete0111111"></span>', 'OP3 case 7 (unknown tag)'],

            // OP3 5-open case: unmatched inner <op: is escaped, all opens auto-close.
            [
                '<op:i><op:<op:i><op:i><op:i><op:333333>',
                '<span class="op_i">&lt;op:<span class="op_i"><span class="op_i"><span class="op_i"><span class="op_333333"></span></span></span></span></span>',
                'OP3 5 open tags',
            ],

            // OP3 case 9: invalid colour dropped, op:font keeps its (empty) style attribute.
            ['<op:font color="expression(alert(0))">Attack!</op:font>', '<span class="op_font" style="">Attack!</span>', 'OP3 case 9 (invalid colour)'],

            // Nested, mismatched, stray-close.
            ['<op:b><op:i>x</op:i></op:b>', '<span class="op_b"><span class="op_i">x</span></span>', 'nested'],
            ['<op:b>x</op:i>', '<span class="op_b">x</span>', 'mismatched close still closes a span'],
            ['</op:b>text', 'text', 'stray close is dropped (delta: OP3 emitted an orphan </span>)'],

            // op:color validation.
            ['<op:color code="#ff0000">x</op:color>', '<span class="op_color" style="color:#ff0000">x</span>', 'valid colour'],
            ['<op:color code="red">x</op:color>', '<span class="op_color">x</span>', 'named colour dropped'],

            // op:font size mapping and clamping.
            ['<op:font size="5">x</op:font>', '<span class="op_font" style="font-size:large">x</span>', 'font size 5'],
            ['<op:font size="9">x</op:font>', '<span class="op_font" style="">x</span>', 'font size out of range dropped'],
            ['<op:font size="abc">x</op:font>', '<span class="op_font" style="">x</span>', 'non-int font size dropped'],
            ['<op:font color="#00ff00" size="3">x</op:font>', '<span class="op_font" style="color:#00ff00;font-size:small">x</span>', 'font colour + size'],
        ];

        foreach ($cases as [$input, $expected, $label]) {
            $this->assertSame($expected, $this->render($input), $label);
        }
    }

    public function test_literal_html_stays_escaped(): void
    {
        $html = $this->render('<b>bold</b> <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
    }

    public function test_url_with_query_ampersand_inside_a_span_is_single_escaped(): void
    {
        $html = $this->render('<op:b>https://example.com/?a=1&b=2</op:b>');

        $this->assertStringContainsString('<span class="op_b">', $html);
        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
        $this->assertStringNotContainsString('&b=2"', $html); // a raw & would be unsafe
        $this->assertStringNotContainsString('&amp;amp;', $html); // not double-escaped
    }

    public function test_url_with_query_ampersand_outside_a_span_is_linked(): void
    {
        $html = $this->render('see https://example.com/?a=1&b=2 <op:b>x</op:b>');

        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('<span class="op_b">x</span>', $html);
    }

    public function test_attribute_breakout_via_onmouseover_is_neutralized(): void
    {
        $html = $this->render('<op:color code=""onmouseover="alert(1)">x</op:color>');

        $this->assertSame('<span class="op_color">x</span>', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
    }

    public function test_script_breakout_after_a_valid_tag_stays_escaped(): void
    {
        $html = $this->render('<op:color code="#000000">"><script>alert(1)</script></op:color>');

        $this->assertStringContainsString('<span class="op_color" style="color:#000000">', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_newlines_become_br_inside_a_span(): void
    {
        $this->assertStringContainsString('a<br', $this->render("<op:b>a\nb</op:b>"));
    }

    public function test_null_renders_nothing(): void
    {
        $this->assertSame('', (string) Op3Text::render(null));
    }
}
