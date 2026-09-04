<?php

namespace Tests\Unit\Support;

use App\Support\MarkdownText;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Markdown body pipeline: CommonMark + a GitHub-flavoured subset, then a
 * symfony/html-sanitizer allowlist belt. The XSS cases assert the two layers together — raw HTML is
 * escaped, unsafe links are dropped, and nothing outside the allowlist survives.
 */
class MarkdownTextTest extends TestCase
{
    private function render(string $markdown): string
    {
        return MarkdownText::render($markdown)->toHtml();
    }

    public function test_renders_headings(): void
    {
        $this->assertStringContainsString('<h1>Hi</h1>', $this->render('# Hi'));
        $this->assertStringContainsString('<h3>Sub</h3>', $this->render('### Sub'));
    }

    public function test_renders_bold_italic_and_strikethrough(): void
    {
        $html = $this->render('**b** *i* ~~s~~');

        $this->assertStringContainsString('<strong>b</strong>', $html);
        $this->assertStringContainsString('<em>i</em>', $html);
        $this->assertStringContainsString('<del>s</del>', $html);
    }

    public function test_renders_a_bulleted_list(): void
    {
        $html = $this->render("- one\n- two");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
    }

    public function test_renders_a_table(): void
    {
        $html = $this->render("| a | b |\n|---|---|\n| 1 | 2 |");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>a</th>', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
    }

    public function test_soft_break_becomes_a_line_break(): void
    {
        // renderer.soft_break = "<br>\n": a single newline reads as a line break (plaintext-like).
        $this->assertStringContainsString('<br', $this->render("a\nb"));
    }

    public function test_autolinks_a_bare_url_and_a_www_host(): void
    {
        $html = $this->render('see http://example.com and www.foo.org');

        $this->assertStringContainsString('href="http://example.com"', $html);
        $this->assertStringContainsString('www.foo.org', $html);
        // The sanitizer forces a hardened rel + a new tab on every link.
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_raw_script_is_escaped_to_text(): void
    {
        $html = $this->render('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_raw_img_with_event_handler_is_escaped(): void
    {
        $html = $this->render('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $html);
        // The whole tag became inert text; no live onerror attribute remains.
        $this->assertStringNotContainsString('onerror=alert', $html);
    }

    public function test_event_handler_attribute_attempt_is_neutralized(): void
    {
        $html = $this->render('<div onclick="alert(1)">x</div>');

        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringNotContainsString('onclick="', $html);
    }

    public function test_javascript_scheme_link_is_not_linked(): void
    {
        $html = $this->render('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_data_scheme_link_is_not_linked(): void
    {
        $html = $this->render('[click](data:text/html,<script>alert(1)</script>)');

        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_deep_nesting_is_bounded(): void
    {
        // max_nesting_level = 20 caps a blockquote bomb; the parser must not recurse per input level.
        $html = $this->render(str_repeat('> ', 200).'deep');

        $this->assertLessThanOrEqual(20, substr_count($html, '<blockquote>'));
    }

    public function test_sanitizer_strips_a_disallowed_attribute_and_forces_link_hardening(): void
    {
        // CommonMark emits <a href title>; only the sanitizer drops the title and forces rel/target,
        // so this passes only if the sanitizer belt ran.
        $html = $this->render('[x](http://e.com "a title")');

        $this->assertStringContainsString('href="http://e.com"', $html);
        $this->assertStringNotContainsString('title=', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_image_renders_as_escaped_alt_text_not_an_img_tag(): void
    {
        $html = $this->render('![alt <b>x</b>](http://e.com/a.png)');

        $this->assertStringNotContainsString('<img', $html);
        // The alt text survives, escaped — a plain tag-drop would have deleted it with the <img>.
        $this->assertStringContainsString('alt &lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function test_long_body_is_not_truncated_by_the_sanitizer(): void
    {
        // The sanitizer's default 20_000-byte input cap is disabled; a long body keeps its tail.
        $html = $this->render(str_repeat('a', 25000).' MARKEREND');

        $this->assertStringContainsString('MARKEREND', $html);
    }

    public function test_excerpt_strips_tags_before_decoding_entities(): void
    {
        // Decoding entities before strip_tags would let it eat the user's literal <b> along with the
        // real <strong>.
        $this->assertSame('bold <b>x</b>', MarkdownText::excerpt('**bold** <b>x</b>'));
    }

    public function test_excerpt_decodes_entities(): void
    {
        $this->assertSame('A & B', MarkdownText::excerpt('A & B'));
    }

    public function test_excerpt_collapses_whitespace_runs_to_single_spaces(): void
    {
        $this->assertSame('First Second third', MarkdownText::excerpt("First\n\nSecond   third"));
    }

    public function test_excerpt_is_cut_to_display_width_108(): void
    {
        $this->assertSame(str_repeat('a', 108), MarkdownText::excerpt(str_repeat('a', 200)));
    }
}
