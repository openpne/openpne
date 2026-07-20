<?php

namespace Tests\Unit\Support;

use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use PHPUnit\Framework\TestCase;

class BodyRendererTest extends TestCase
{
    public function test_plain_format_renders_the_plain_path(): void
    {
        $html = (string) BodyRenderer::render('<op:b>x</op:b> <b>y</b>', BodyFormat::Plain);

        // Plain does not interpret op tags — everything is escaped, no spans.
        $this->assertStringNotContainsString('<span', $html);
        $this->assertStringContainsString('&lt;op:b&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;y&lt;/b&gt;', $html);
    }

    public function test_op3_format_renders_decoration_spans(): void
    {
        $html = (string) BodyRenderer::render('<op:b>x</op:b>', BodyFormat::Op3);

        $this->assertSame('<span class="op_b">x</span>', $html);
    }

    public function test_markdown_falls_back_to_the_plain_path_for_now(): void
    {
        $plain = (string) BodyRenderer::render('<op:b>x</op:b>', BodyFormat::Plain);
        $markdown = (string) BodyRenderer::render('<op:b>x</op:b>', BodyFormat::Markdown);

        $this->assertSame($plain, $markdown);
    }

    public function test_excerpt_strips_op_tags_for_every_format(): void
    {
        foreach (BodyFormat::cases() as $format) {
            $this->assertSame('Bold and plain', BodyRenderer::excerpt('<op:b>Bold</op:b> and plain', $format), $format->value);
        }
    }
}
