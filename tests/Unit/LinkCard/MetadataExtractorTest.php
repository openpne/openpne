<?php

declare(strict_types=1);

namespace Tests\Unit\LinkCard;

use App\LinkCard\LinkMetadata;
use App\LinkCard\MetadataExtractor;
use PHPUnit\Framework\TestCase;

class MetadataExtractorTest extends TestCase
{
    private const URL = 'https://example.com/article/1';

    public function test_it_prefers_open_graph(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <title>Title element</title>
            <meta property="og:title" content="Open Graph title">
            <meta property="og:description" content="Open Graph description">
            <meta property="og:site_name" content="Example Journal">
            <meta property="og:image" content="https://cdn.example.com/hero.jpg">
            <meta name="twitter:title" content="Twitter title">
            <meta name="description" content="Plain description">
            </head><body>x</body></html>
            HTML);

        $this->assertSame('Open Graph title', $metadata->title);
        $this->assertSame('Open Graph description', $metadata->description);
        $this->assertSame('Example Journal', $metadata->siteName);
        $this->assertSame('https://cdn.example.com/hero.jpg', $metadata->imageUrl);
    }

    public function test_it_falls_back_to_twitter_cards(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <title>Title element</title>
            <meta name="twitter:title" content="Twitter title">
            <meta name="twitter:description" content="Twitter description">
            <meta name="twitter:image" content="/card.png">
            </head></html>
            HTML);

        $this->assertSame('Twitter title', $metadata->title);
        $this->assertSame('Twitter description', $metadata->description);
        $this->assertSame('https://example.com/card.png', $metadata->imageUrl);
    }

    public function test_it_falls_back_to_plain_html(): void
    {
        $metadata = $this->extract('<html><head><title>Just a title</title><meta name="description" content="Just a description"></head></html>');

        $this->assertSame('Just a title', $metadata->title);
        $this->assertSame('Just a description', $metadata->description);
        $this->assertNull($metadata->imageUrl);
        // Every card gets a provenance line, so the host stands in for a missing og:site_name.
        $this->assertSame('example.com', $metadata->siteName);
    }

    public function test_a_page_with_no_metadata_yields_nothing_usable(): void
    {
        $metadata = $this->extract('<html><body><p>Hello</p></body></html>');

        $this->assertFalse($metadata->isUsable());
        $this->assertNull($metadata->title);
    }

    public function test_it_reads_a_shift_jis_page(): void
    {
        // Not an edge case for this app: plenty of long-lived Japanese pages are still CP932, and
        // skipping the conversion does not fail — it stores mojibake.
        $html = mb_convert_encoding(
            '<html><head><meta charset="Shift_JIS"><title>日記のタイトル</title></head></html>',
            'SJIS-win',
            'UTF-8',
        );

        $metadata = $this->extract($html, 'shift_jis');

        $this->assertSame('日記のタイトル', $metadata->title);
    }

    public function test_it_reads_a_shift_jis_page_that_declares_nothing_in_its_headers(): void
    {
        $html = mb_convert_encoding(
            '<html><head><meta charset="Shift_JIS"><title>タイトル</title></head></html>',
            'SJIS-win',
            'UTF-8',
        );

        $this->assertSame('タイトル', $this->extract($html, null)->title);
    }

    public function test_it_reads_a_euc_jp_page(): void
    {
        $html = mb_convert_encoding(
            '<html><head><meta charset="EUC-JP"><title>日本語</title></head></html>',
            'eucJP-win',
            'UTF-8',
        );

        $this->assertSame('日本語', $this->extract($html, 'euc-jp')->title);
    }

    public function test_a_charset_that_does_not_fit_the_bytes_falls_through_to_detection(): void
    {
        // A CMS template left declaring Shift_JIS while the page is really UTF-8 is common enough
        // that the declaration is trusted only as far as it works.
        $html = '<html><head><meta charset="Shift_JIS"><title>実際はUTF-8</title></head></html>';

        $this->assertSame('実際はUTF-8', $this->extract($html, 'shift_jis')->title);
    }

    public function test_an_unknown_charset_label_does_not_break_the_parse(): void
    {
        $this->assertSame('Fine', $this->extract('<html><head><title>Fine</title></head></html>', 'x-nonsense-9000')->title);
    }

    public function test_it_discovers_a_json_oembed_endpoint(): void
    {
        $metadata = $this->extract('<html><head><link rel="alternate" type="application/json+oembed" href="/services/oembed?url=1"></head></html>');

        $this->assertSame('https://example.com/services/oembed?url=1', $metadata->oembedUrl);
    }

    public function test_an_xml_only_oembed_provider_is_treated_as_having_none(): void
    {
        // There is no XML reader here, so discovering it would only produce a request whose result
        // gets thrown away.
        $metadata = $this->extract('<html><head><link rel="alternate" type="text/xml+oembed" href="/oembed.xml"></head></html>');

        $this->assertNull($metadata->oembedUrl);
    }

    public function test_relative_references_resolve_against_the_response_url(): void
    {
        // After a redirect crosses hosts, `/thumb.jpg` on the page we arrived at is a different file
        // from the same path on the page we asked for, so the response URL is the base.
        $metadata = $this->extract(
            '<html><head><meta property="og:image" content="../hero.png"><link rel="alternate" type="application/json+oembed" href="oembed"></head></html>',
            null,
            'https://redirected.example.net/a/b/page.html',
        );

        $this->assertSame('https://redirected.example.net/a/hero.png', $metadata->imageUrl);
        $this->assertSame('https://redirected.example.net/a/b/oembed', $metadata->oembedUrl);
    }

    public function test_it_refuses_a_non_http_image_reference(): void
    {
        $metadata = $this->extract('<html><head><meta property="og:image" content="data:image/png;base64,AAAA"></head></html>');

        $this->assertNull($metadata->imageUrl);
    }

    public function test_markup_in_a_meta_value_stays_text(): void
    {
        // Nothing here produces HTML; a card is drawn from escaped text. The value comes out as the
        // characters the page wrote, which the renderer then escapes.
        $metadata = $this->extract('<html><head><meta property="og:title" content="&lt;script&gt;alert(1)&lt;/script&gt;"></head></html>');

        $this->assertSame('<script>alert(1)</script>', $metadata->title);
    }

    public function test_control_characters_are_stripped_and_whitespace_collapsed(): void
    {
        // These values reach mail subjects and log lines as well as the page, where a stray newline
        // is a header-injection shape.
        $metadata = $this->extract("<html><head><title>Line\r\none\u{200B}\ttwo   three</title></head></html>");

        $this->assertSame('Line one two three', $metadata->title);
    }

    public function test_long_values_are_cut_to_the_stored_length(): void
    {
        $metadata = $this->extract(sprintf(
            '<html><head><meta property="og:title" content="%s"><meta property="og:description" content="%s"></head></html>',
            str_repeat('あ', 400),
            str_repeat('い', 700),
        ));

        $this->assertSame(300, mb_strlen((string) $metadata->title));
        $this->assertSame(500, mb_strlen((string) $metadata->description));
    }

    public function test_the_first_occurrence_of_a_repeated_property_wins(): void
    {
        // A page listing several og:image puts its preferred one first.
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <meta property="og:image" content="https://cdn.example.com/first.jpg">
            <meta property="og:image" content="https://cdn.example.com/second.jpg">
            </head></html>
            HTML);

        $this->assertSame('https://cdn.example.com/first.jpg', $metadata->imageUrl);
    }

    public function test_malformed_markup_yields_empty_metadata_rather_than_throwing(): void
    {
        $metadata = $this->extract('<html><head><title>Unclosed <meta property="og:image" content=');

        $this->assertNotNull($metadata);
    }

    public function test_it_prefers_the_secure_image_url_variant(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <meta property="og:image" content="http://cdn.example.com/insecure.jpg">
            <meta property="og:image:secure_url" content="https://cdn.example.com/secure.jpg">
            </head></html>
            HTML);

        $this->assertSame('https://cdn.example.com/secure.jpg', $metadata->imageUrl);
    }

    private function extract(string $html, ?string $charset = null, string $url = self::URL): LinkMetadata
    {
        return (new MetadataExtractor)->extract($html, $charset, $url);
    }
}
