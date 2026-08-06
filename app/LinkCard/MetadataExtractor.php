<?php

declare(strict_types=1);

namespace App\LinkCard;

use DOMDocument;
use DOMElement;
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
    /**
     * @param  string  $html  The response body, in whatever encoding it arrived in.
     * @param  string|null  $charset  The Content-Type charset, if the response declared one.
     * @param  string  $url  The URL of the response, used to resolve relative references.
     */
    public function extract(string $html, ?string $charset, string $url): LinkMetadata
    {
        $document = $this->parse(Encoding::toUtf8($html, $charset));

        if ($document === null) {
            return new LinkMetadata;
        }

        $meta = $this->metaContent($document);
        // A <base href> is what the document itself says relative references resolve against, and
        // browsers honour it. The fetcher guards the result either way, so respecting it costs no
        // safety and gets the right file from pages that set one.
        $base = $this->baseHref($document, $url) ?? $url;

        return new LinkMetadata(
            title: $meta['og:title'] ?? $meta['twitter:title'] ?? $this->titleElement($document),
            description: $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null,
            // Falling back to the host gives every card a provenance line, which is the part a reader
            // uses to decide whether to follow the link at all.
            siteName: $meta['og:site_name'] ?? $this->hostOf($url),
            authorName: $meta['article:author'] ?? $meta['twitter:creator'] ?? null,
            imageUrl: LinkUrl::resolve($this->imageReference($document, $meta), $base),
            oembedUrl: LinkUrl::resolve($this->oembedHref($document), $base),
        );
    }

    /**
     * A hostile or merely broken page must not take the fetch down with it, so a parse failure is
     * "this page said nothing" rather than an exception.
     */
    private function parse(string $html): ?DOMDocument
    {
        try {
            return (new HTML5(['disable_html_ns' => true]))->loadHTML($html);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The image to use, honouring Open Graph's grouping rules.
     *
     * Per ogp.me, a structured property (`og:image:url`, `og:image:secure_url`) belongs to the most
     * recent root `og:image` tag, and the first object listed is the page's preferred one. Flattening
     * the tags by name and then preferring `secure_url` mixes the groups: given first.jpg, then
     * second.jpg with second-secure.jpg after it, that picks the *second* image's secure URL, which
     * the page ranked below the first. So the tags are walked in document order and only the first
     * group is considered.
     */
    private function imageReference(DOMDocument $document, array $meta): ?string
    {
        $group = [];

        foreach ($document->getElementsByTagName('meta') as $element) {
            $key = strtolower(trim($element->getAttribute('property')));
            $content = trim($element->getAttribute('content'));

            if ($content === '') {
                continue;
            }

            // A second root tag opens the next image object, so the first group is complete.
            if ($key === 'og:image' && $group !== []) {
                break;
            }

            // og:image:url is defined as identical to og:image; secure_url is the https variant of
            // the same picture, so preferring it stays inside this one group.
            if (in_array($key, ['og:image', 'og:image:url', 'og:image:secure_url'], true)) {
                $group[$key] ??= $content;
            }
        }

        return $group['og:image:secure_url']
            ?? $group['og:image:url']
            ?? $group['og:image']
            ?? $meta['twitter:image']
            ?? null;
    }

    /**
     * Every <meta> keyed by its `property` or `name`, lowercased.
     *
     * The first occurrence wins: a page repeating a property lists its preferred value first, and
     * taking the last would quietly pick the least representative.
     *
     * @return array<string, string>
     */
    private function metaContent(DOMDocument $document): array
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

    private function titleElement(DOMDocument $document): ?string
    {
        $titles = $document->getElementsByTagName('title');

        return $titles->length > 0 ? $titles->item(0)?->textContent : null;
    }

    /** The document's declared base for relative references, if it sets a usable one. */
    private function baseHref(DOMDocument $document, string $url): ?string
    {
        $bases = $document->getElementsByTagName('base');
        $href = $bases->length > 0 ? trim((string) $bases->item(0)?->getAttribute('href')) : '';

        return $href === '' ? null : LinkUrl::resolve($href, $url);
    }

    /**
     * The JSON oEmbed endpoint the page advertises.
     *
     * The spec also defines an XML flavour, which this app has no reader for; a page offering only
     * that is treated as offering none rather than being fetched and discarded.
     */
    private function oembedHref(DOMDocument $document): ?string
    {
        foreach ($document->getElementsByTagName('link') as $element) {
            if (! $this->hasRel($element, 'alternate')) {
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

    /**
     * Whether $element's rel contains $token.
     *
     * rel is a space-separated token list, so this is an exact match against the tokens rather than a
     * substring test — `rel="notalternate"` contains "alternate" but is not it.
     */
    private function hasRel(DOMElement $element, string $token): bool
    {
        $tokens = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel')))) ?: [];

        return in_array($token, $tokens, true);
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
