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

    public function test_plaintext_plain_passes_through_unchanged(): void
    {
        // text/plain mail: a plain body is emitted verbatim, newlines and all — no width cut.
        $this->assertSame("hi\nthere", BodyRenderer::plainText("hi\nthere", BodyFormat::Plain));
    }

    public function test_plaintext_op3_strips_decoration_but_keeps_newlines(): void
    {
        // Raw and entity-encoded <op:*> tags both go; the text between them and its newlines stay.
        $this->assertSame("bold\nsecond", BodyRenderer::plainText("<op:b>bold</op:b>\nsecond", BodyFormat::Op3));
        $this->assertSame('x', BodyRenderer::plainText('&lt;op:b&gt;x&lt;/op:b&gt;', BodyFormat::Op3));
    }

    public function test_plaintext_markdown_flattens_to_text_with_newlines(): void
    {
        // No literal markup reaches the mail: `**bold**` renders to `bold`.
        $this->assertSame('bold', BodyRenderer::plainText('**bold**', BodyFormat::Markdown));
        // A blank line separates paragraphs; a single soft break stays a single newline.
        $this->assertSame("a\n\nb", BodyRenderer::plainText("a\n\nb", BodyFormat::Markdown));
        $this->assertSame("line1\nline2", BodyRenderer::plainText("line1\nline2", BodyFormat::Markdown));
        // List items land on their own lines (markers dropped in a text/plain context).
        $this->assertSame("one\ntwo", BodyRenderer::plainText("- one\n- two", BodyFormat::Markdown));
    }
}
