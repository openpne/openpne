<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use PHPUnit\Framework\TestCase;

/**
 * What a link card is fetched for must be exactly what the reader sees as a link. A URL that is
 * linked but gets no card, or a card for something that is not a link, both read as bugs — so
 * extraction is dispatched per format alongside rendering rather than done with one regex.
 */
class BodyRendererUrlsTest extends TestCase
{
    public function test_a_plain_body_yields_its_autolinked_urls_in_order(): void
    {
        $this->assertSame(
            ['https://example.com/one', 'https://example.com/two'],
            BodyRenderer::urls('First https://example.com/one then https://example.com/two', BodyFormat::Plain),
        );
    }

    public function test_a_bare_www_host_gets_the_scheme_the_renderer_gives_it(): void
    {
        // BodyText::link() prefixes http:// for a bare www. host, so extraction must too — otherwise
        // the body shows a link with no card beside it.
        $this->assertSame(['http://www.example.com/x'], BodyRenderer::urls('see www.example.com/x', BodyFormat::Plain));
    }

    public function test_trailing_punctuation_is_not_part_of_the_url(): void
    {
        $this->assertSame(['https://example.com/a'], BodyRenderer::urls('Read https://example.com/a.', BodyFormat::Plain));
    }

    public function test_a_markdown_body_yields_both_inline_links_and_autolinks(): void
    {
        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            BodyRenderer::urls('[label](https://example.com/a) and https://example.com/b', BodyFormat::Markdown),
        );
    }

    public function test_a_markdown_code_span_is_not_a_link(): void
    {
        // It is not linked on the page either, so a card for it would be surprising.
        $this->assertSame(
            ['https://example.com/real'],
            BodyRenderer::urls('`https://example.com/code` then <https://example.com/real>', BodyFormat::Markdown),
        );
    }

    public function test_a_markdown_fenced_block_is_not_a_link(): void
    {
        $this->assertSame([], BodyRenderer::urls("```\nhttps://example.com/fenced\n```", BodyFormat::Markdown));
    }

    public function test_an_op3_body_yields_the_urls_between_its_decoration_tags(): void
    {
        $this->assertSame(
            ['https://example.com/a'],
            BodyRenderer::urls('<op:color color="#ff0000">See https://example.com/a</op:color>', BodyFormat::Op3),
        );
    }

    public function test_an_op3_tag_attribute_is_not_mistaken_for_a_url(): void
    {
        // The tags are stripped before matching, so a decoration attribute cannot read as a link.
        $this->assertSame([], BodyRenderer::urls('<op:size size="3">plain text</op:size>', BodyFormat::Op3));
    }

    public function test_an_empty_body_yields_nothing(): void
    {
        foreach (BodyFormat::cases() as $format) {
            $this->assertSame([], BodyRenderer::urls(null, $format));
            $this->assertSame([], BodyRenderer::urls('', $format));
        }
    }
}
