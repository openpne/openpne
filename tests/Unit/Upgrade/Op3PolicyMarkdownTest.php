<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use App\Support\MarkdownText;
use App\Upgrade\Runner\Op3PolicyMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * The OpenPNE 3 policy body → Markdown rewrite, asserted on what the reader ends up seeing: the
 * converted source is run back through MarkdownText, the renderer the pages use.
 */
class Op3PolicyMarkdownTest extends TestCase
{
    public function test_plain_text_keeps_its_line_breaks(): void
    {
        // OpenPNE 3 printed nl2br(...), and MarkdownText renders a soft break as <br>.
        $html = $this->render("第1条(適用)\n本規約は、当SNSの利用に関し適用されます。");

        $this->assertStringContainsString("第1条(適用)<br />\n本規約は、当SNSの利用に関し適用されます。", $html);
    }

    public function test_markdown_punctuation_in_plain_text_stays_literal(): void
    {
        $html = $this->render("# 見出しではない\n- 箇条書きではない\n*注意* _強調_ [括弧]\n> 引用ではない\n1. 一号");

        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('<ul>', $html);
        $this->assertStringNotContainsString('<ol>', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringNotContainsString('<blockquote>', $html);
        $this->assertStringContainsString('# 見出しではない', $html);
        $this->assertStringContainsString('- 箇条書きではない', $html);
        $this->assertStringContainsString('*注意* _強調_ [括弧]', $html);
        $this->assertStringContainsString('&gt; 引用ではない', $html);
        // The escape sits on the '.', so no stray backslash reaches the reader.
        $this->assertStringContainsString('1. 一号', $html);
        $this->assertStringNotContainsString('\\', $html);
    }

    public function test_a_line_of_equals_does_not_become_a_heading(): void
    {
        $html = $this->render("規約\n====");

        $this->assertStringNotContainsString('<h1>', $html);
        // The sanitizer numeric-encodes the '=' characters, so read the text as a reader would.
        $this->assertStringContainsString('====', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_html_markup_survives_as_markdown(): void
    {
        $html = $this->render("<h2>第1条</h2>\n<p>本規約は…</p>\n<ul><li>ひとつめ</li><li>ふたつめ</li></ul>\n<a href=\"https://example.test\">サイト</a>");

        $this->assertStringContainsString('<h2>第1条</h2>', $html);
        $this->assertStringContainsString('<li>ひとつめ</li>', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
        $this->assertStringNotContainsString('&lt;h2&gt;', $html);
    }

    public function test_script_and_style_are_dropped(): void
    {
        $html = $this->render("<p>本文</p>\n<script>alert(1)</script><style>body{}</style>");

        $this->assertStringContainsString('本文', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('body{}', $html);
    }

    public function test_urls_and_hosts_become_links(): void
    {
        // A documented departure from OpenPNE 3 (which linked nothing here): MarkdownText autolinks,
        // and escaping the colon / dots to prevent it would leave `https\://` in the operator's editor.
        $this->assertStringContainsString(
            '<a href="https://example.test/terms"',
            $this->render('詳しくは https://example.test/terms をご覧ください。'),
        );
        $this->assertStringContainsString(
            '<a href="http://www.example.test"',
            $this->render('詳しくは www.example.test をご覧ください。'),
        );
    }

    public function test_an_email_address_survives_as_text(): void
    {
        // Autolinked to a mailto: the sanitizer allows only http(s) hrefs, so what is left is the
        // address itself — readable, which is what the policy needs.
        $html = $this->render('お問い合わせは info@example.test まで。');

        $this->assertStringNotContainsString('href="mailto:', $html);
        $this->assertStringContainsString('info', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('@example.test', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_an_empty_body_is_left_alone(): void
    {
        $this->assertSame('', Op3PolicyMarkdown::convert(''));
        $this->assertSame("\n", Op3PolicyMarkdown::convert("\n"));
    }

    private function render(string $op3): string
    {
        return MarkdownText::render(Op3PolicyMarkdown::convert($op3))->toHtml();
    }
}
