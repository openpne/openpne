<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MarkdownText;
use Tests\TestCase;

class MarkdownTextOperatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sns.example']);
    }

    public function test_an_image_is_drawn_for_operator_text_and_stays_alt_text_for_a_member(): void
    {
        $source = '![ロゴ](https://cdn.example/logo.png "タイトル")';

        $this->assertStringContainsString('<img src="https://cdn.example/logo.png" alt="ロゴ" title="タイトル"', $this->operator($source));
        $this->assertStringNotContainsString('<img', MarkdownText::render($source)->toHtml());
    }

    public function test_an_image_on_this_site_is_drawn_by_its_path(): void
    {
        $this->assertStringContainsString('<img src="/banner/image/abc" alt="b"', $this->operator('![b](/banner/image/abc)'));
    }

    public function test_an_image_with_a_data_or_script_url_is_not_loaded(): void
    {
        // CommonMark itself lets a raster data: image through, so this one reaches the sanitizer.
        $this->assertStringNotContainsString('data:', $this->operator('![x](data:image/png;base64,iVBORw0KGgo=)'));
        $this->assertStringNotContainsString('javascript:', $this->operator('![x](javascript:alert(1))'));
    }

    public function test_a_site_path_link_opens_in_place_and_an_external_one_in_a_new_tab(): void
    {
        $this->assertStringContainsString('<a href="/member/config">設定</a>', $this->operator('[設定](/member/config)'));

        $external = $this->operator('[外部](https://other.example/)');
        $this->assertStringContainsString('<a href="https://other.example/" target="_blank" rel="noopener noreferrer nofollow">外部<span class="sr-only"', $external);
    }

    public function test_a_path_a_browser_reads_as_another_host_is_not_treated_as_this_site(): void
    {
        foreach (['//other.example/x', '/\\other.example/x'] as $url) {
            $html = $this->operator("[x]({$url})");

            $this->assertStringNotContainsString("<a href=\"{$url}\">", $html, $url);
        }
    }

    public function test_a_member_body_still_refuses_a_relative_link(): void
    {
        $this->assertStringNotContainsString('href="/member/config"', MarkdownText::render('[設定](/member/config)')->toHtml());
    }

    public function test_raw_html_in_operator_text_is_printed_as_text(): void
    {
        $html = $this->operator("<script>alert(1)</script>\n\n<form action=\"https://evil.example/\"><input name=\"password\"></form>");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    private function operator(string $markdown): string
    {
        return MarkdownText::renderOperator($markdown)->toHtml();
    }
}
