<?php

declare(strict_types=1);

namespace App\LinkCard;

use Masterminds\HTML5;
use Throwable;

/**
 * Reads a page's own description of itself out of its markup.
 *
 * Pure by construction: bytes in, LinkMetadata out. It never fetches anything — not the oEmbed
 * endpoint it discovers, not the image it names — so every test of it is a test of parsing rather
 * than of a mock's behaviour, and no network policy can be accidentally bypassed by living in here.
 *
 * Field precedence is Open Graph, then Twitter Cards, then plain HTML. Open Graph is what publishers
 * actually maintain, and reading it costs nothing beyond the one response already in hand — unlike
 * oEmbed, which is a second request. (Mastodon prefers oEmbed; this is a deliberate divergence for
 * the common case of one request per link.) The oEmbed endpoint is only *discovered* here; whether
 * it is worth calling is decided by the caller, from what came back missing.
 */
final class MetadataExtractor
{
    /** Stored lengths. Beyond these a card is not showing the text anyway, and the columns are sized to match. */
    private const LIMITS = ['title' => 300, 'description' => 500, 'siteName' => 100, 'authorName' => 100];

    /**
     * @param  string  $html  The response body, in whatever encoding it arrived in.
     * @param  string|null  $charset  The Content-Type charset, if the response declared one.
     * @param  string  $url  The URL of the response, used to resolve relative references.
     */
    public function extract(string $html, ?string $charset, string $url): LinkMetadata
    {
        $html = Encoding::toUtf8($html, $charset);

        $document = $this->parse($html);

        if ($document === null) {
            return new LinkMetadata;
        }

        $meta = $this->metaContent($document);

        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? $this->titleElement($document);
        $description = $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null;
        $image = $meta['og:image:secure_url'] ?? $meta['og:image:url'] ?? $meta['og:image'] ?? $meta['twitter:image'] ?? null;

        return new LinkMetadata(
            title: $this->clean($title, self::LIMITS['title']),
            description: $this->clean($description, self::LIMITS['description']),
            // Falling back to the host gives every card a provenance line, which is the part a reader
            // uses to decide whether to follow the link at all.
            siteName: $this->clean($meta['og:site_name'] ?? $this->hostOf($url), self::LIMITS['siteName']),
            authorName: $this->clean($meta['article:author'] ?? $meta['twitter:creator'] ?? null, self::LIMITS['authorName']),
            imageUrl: LinkUrl::resolve($image, $url),
            oembedUrl: LinkUrl::resolve($this->oembedHref($document), $url),
        );
    }

    /**
     * A hostile or merely broken page must not take the fetch down with it, so a parse failure is
     * "this page said nothing" rather than an exception.
     */
    private function parse(string $html): ?\DOMDocument
    {
        try {
            return (new HTML5(['disable_html_ns' => true]))->loadHTML($html);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every <meta> keyed by its `property` or `name`, lowercased.
     *
     * The first occurrence wins: a page repeating og:image lists its preferred one first, and taking
     * the last would quietly pick the least representative.
     *
     * @return array<string, string>
     */
    private function metaContent(\DOMDocument $document): array
    {
        $found = [];

        foreach ($document->getElementsByTagName('meta') as $element) {
            $key = strtolower(trim($element->getAttribute('property') ?: $element->getAttribute('name')));
            $content = trim($element->getAttribute('content'));

            if ($key !== '' && $content !== '' && ! isset($found[$key])) {
                $found[$key] = $content;
            }
        }

        return $found;
    }

    private function titleElement(\DOMDocument $document): ?string
    {
        $titles = $document->getElementsByTagName('title');

        return $titles->length > 0 ? $titles->item(0)?->textContent : null;
    }

    /**
     * The JSON oEmbed endpoint the page advertises.
     *
     * The spec also defines an XML flavour, which this app has no reader for; a page offering only
     * that is treated as offering none rather than being fetched and discarded.
     */
    private function oembedHref(\DOMDocument $document): ?string
    {
        foreach ($document->getElementsByTagName('link') as $element) {
            if (! str_contains(strtolower($element->getAttribute('rel')), 'alternate')) {
                continue;
            }

            $type = strtolower(trim($element->getAttribute('type')));
            $href = trim($element->getAttribute('href'));

            if ($href !== '' && ($type === 'application/json+oembed' || $type === 'text/json+oembed')) {
                return $href;
            }
        }

        return null;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Collapse whitespace, drop control characters, and cut to the stored length.
     *
     * The control-character strip is not cosmetic: these values reach mail subjects and log lines as
     * well as the page, where a stray newline is a header-injection shape.
     */
    private function clean(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
