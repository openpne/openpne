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

    public function test_markdown_format_renders_markdown_not_the_plain_path(): void
    {
        $html = (string) BodyRenderer::render('**bold**', BodyFormat::Markdown);

        // Markdown emphasis becomes a tag; the plain path would escape the asterisks verbatim.
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_excerpt_is_format_aware(): void
    {
        // Plain and op3 strip <op:*> tags (BodyText::excerpt); markdown flattens its rendered HTML.
        $this->assertSame('Bold and plain', BodyRenderer::excerpt('<op:b>Bold</op:b> and plain', BodyFormat::Plain));
        $this->assertSame('Bold and plain', BodyRenderer::excerpt('<op:b>Bold</op:b> and plain', BodyFormat::Op3));
        $this->assertSame('Bold and plain', BodyRenderer::excerpt('**Bold** and plain', BodyFormat::Markdown));
    }
}
