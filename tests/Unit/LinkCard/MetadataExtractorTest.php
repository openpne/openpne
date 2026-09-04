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

    public function test_it_reads_an_iso_2022_jp_page(): void
    {
        // The one legacy encoding a UTF-8 check cannot rule out: it encodes Japanese entirely within
        // the ASCII byte range using escape sequences, so testing UTF-8 first and returning early
        // leaves the title full of raw ESC $ B sequences.
        $html = mb_convert_encoding(
            '<html><head><title>日本語</title></head></html>',
            'ISO-2022-JP',
            'UTF-8',
        );

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'Precondition: the bytes pass a UTF-8 check.');
        $this->assertSame('日本語', $this->extract($html, 'iso-2022-jp')->title);
    }

    public function test_a_legacy_page_cut_mid_character_keeps_the_readable_prefix(): void
    {
        // The read cap can land mid-character, leaving bytes valid in nothing; converting from UTF-8
        // to UTF-8 then replaces every multi-byte character in the whole prefix, from the declared
        // charset only the broken tail.
        $html = mb_convert_encoding(
            '<html><head><title>日本語のタイトル</title></head></html>',
            'SJIS-win',
            'UTF-8',
        );
        $truncated = substr($html, 0, 26);

        $this->assertFalse(mb_check_encoding($truncated, 'SJIS-win'), 'Precondition: the cut leaves invalid bytes.');
        $this->assertStringStartsWith('日本語', (string) $this->extract($truncated, 'shift_jis')->title);
    }

    public function test_an_iso_2022_jp_page_cut_mid_sequence_still_converts(): void
    {
        // A truncated ISO-2022-JP body fails its validity check but every byte is still ASCII, so a
        // UTF-8 test passes; the condition has to be declared and carrying escapes, not valid.
        $html = mb_convert_encoding(
            '<html><head><title>日本語のタイトル</title></head></html>',
            'ISO-2022-JP',
            'UTF-8',
        );
        $truncated = substr($html, 0, 30);

        $this->assertFalse(mb_check_encoding($truncated, 'ISO-2022-JP'), 'Precondition: the cut leaves invalid bytes.');
        $this->assertTrue(mb_check_encoding($truncated, 'UTF-8'), 'Precondition: the bytes still pass a UTF-8 check.');

        $title = (string) $this->extract($truncated, 'iso-2022-jp')->title;

        $this->assertStringStartsWith('日本語', $title);
        $this->assertStringNotContainsString("\x1B", $title, 'A raw escape sequence survived into the title.');
    }

    public function test_a_page_declared_iso_2022_jp_with_no_escapes_is_treated_as_utf8(): void
    {
        // A declaration with no designation sequence anywhere is a mislabel, and the bytes are
        // almost certainly the UTF-8 they validate as.
        $this->assertSame('実はUTF-8', $this->extract('<html><head><title>実はUTF-8</title></head></html>', 'iso-2022-jp')->title);
    }

    public function test_it_reads_a_charset_declared_the_old_way(): void
    {
        // The spelling the pages this exists for actually carry.
        $html = mb_convert_encoding(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=Shift_JIS"><title>古いページ</title></head></html>',
            'SJIS-win',
            'UTF-8',
        );

        $this->assertSame('古いページ', $this->extract($html, null)->title);
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
        // Nothing here produces HTML: the value comes out as the characters the page wrote, which the
        // renderer then escapes.
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

    public function test_a_structured_image_property_belongs_to_its_own_image(): void
    {
        // Per ogp.me a structured property attaches to the most recent root `og:image`, and the first
        // object listed is the page's preferred one, so flattening by name and preferring
        // `secure_url` would pick the second image.
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <meta property="og:image" content="https://cdn.example.com/first.jpg">
            <meta property="og:image" content="https://cdn.example.com/second.jpg">
            <meta property="og:image:secure_url" content="https://cdn.example.com/second-secure.jpg">
            </head></html>
            HTML);

        $this->assertSame('https://cdn.example.com/first.jpg', $metadata->imageUrl);
    }

    public function test_the_secure_variant_of_the_first_image_still_wins(): void
    {
        // The other half: preferring secure_url is right *within* a group.
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <meta property="og:image" content="http://cdn.example.com/first.jpg">
            <meta property="og:image:secure_url" content="https://cdn.example.com/first-secure.jpg">
            <meta property="og:image" content="https://cdn.example.com/second.jpg">
            </head></html>
            HTML);

        $this->assertSame('https://cdn.example.com/first-secure.jpg', $metadata->imageUrl);
    }

    public function test_og_image_url_opens_an_image_group_of_its_own(): void
    {
        // `og:image:url` is defined as identical to `og:image`, a root tag rather than a structured
        // property, and treating it as the latter lets the second image's `secure_url` win over the
        // first image.
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <meta property="og:image:url" content="https://cdn.example.com/first.jpg">
            <meta property="og:image:url" content="https://cdn.example.com/second.jpg">
            <meta property="og:image:secure_url" content="https://cdn.example.com/second-secure.jpg">
            </head></html>
            HTML);

        $this->assertSame('https://cdn.example.com/first.jpg', $metadata->imageUrl);
    }

    public function test_a_structured_property_before_any_image_belongs_to_nothing(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <meta property="og:image:secure_url" content="https://cdn.example.com/orphan.jpg">
            <meta property="og:image" content="https://cdn.example.com/actual.jpg">
            </head></html>
            HTML);

        $this->assertSame('https://cdn.example.com/actual.jpg', $metadata->imageUrl);
    }

    public function test_the_document_base_is_the_first_base_carrying_an_href(): void
    {
        // A <base> may set only target, leaving the href to a later one; the document base is the
        // first element that actually declares one.
        $metadata = $this->extract(<<<'HTML'
            <html><head>
            <base target="_blank">
            <base href="https://cdn.example.net/assets/">
            <meta property="og:image" content="hero.png">
            </head></html>
            HTML);

        $this->assertSame('https://cdn.example.net/assets/hero.png', $metadata->imageUrl);
    }

    public function test_relative_references_resolve_against_a_declared_base(): void
    {
        // A `<base href>` is what the document says its relative references mean, and browsers
        // honour it; the fetcher guards the result either way.
        $metadata = $this->extract(
            '<html><head><base href="https://cdn.example.net/assets/"><meta property="og:image" content="hero.png"></head></html>',
        );

        $this->assertSame('https://cdn.example.net/assets/hero.png', $metadata->imageUrl);
    }

    public function test_rel_is_matched_as_a_token_not_a_substring(): void
    {
        $metadata = $this->extract('<html><head><link rel="notalternate" type="application/json+oembed" href="/oembed"></head></html>');

        $this->assertNull($metadata->oembedUrl);
    }

    public function test_a_multi_token_rel_is_still_recognised(): void
    {
        $metadata = $this->extract('<html><head><link rel="alternate stylesheet" type="application/json+oembed" href="/oembed"></head></html>');

        $this->assertSame('https://example.com/oembed', $metadata->oembedUrl);
    }

    private function extract(string $html, ?string $charset = null, string $url = self::URL): LinkMetadata
    {
        return (new MetadataExtractor)->extract($html, $charset, $url);
    }
}
