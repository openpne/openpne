<?php

declare(strict_types=1);

namespace App\LinkCard;

use DOMDocument;
use DOMElement;
use Masterminds\HTML5;
use Throwable;

/**
 * Pure by construction: markup in, `LinkMetadata` out, and it never fetches, not even the endpoint
 * or image it names, so no network policy can be bypassed from here. Precedence is Open Graph, then
 * Twitter Cards, then plain HTML, all read from the response in hand; whether the discovered oEmbed
 * endpoint is worth a second request is the caller's decision.
 */
final class MetadataExtractor
{
    /**
     * @param  string  $html  the response body, in whatever encoding it arrived in
     * @param  string|null  $charset  the Content-Type charset, when declared
     * @param  string  $url  the response's own URL, after redirects, which relative references resolve against
     */
    public function extract(string $html, ?string $charset, string $url): LinkMetadata
    {
        $document = $this->parse(Encoding::toUtf8($html, $charset));

        if ($document === null) {
            return new LinkMetadata;
        }

        $meta = $this->metaContent($document);
        // A `<base href>` is what the document says relative references resolve against, and the
        // fetcher guards the result either way, so honouring it costs no safety.
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
     * Per ogp.me a structured property (`og:image:secure_url`) belongs to the most recent root
     * `og:image`, and the first object listed is the page's preferred one. Flattening the tags by
     * name and then preferring `secure_url` would pick the second image's secure URL over the first
     * image, so the tags are walked in document order and only the first group is considered.
     */
    private function imageReference(DOMDocument $document, array $meta): ?string
    {
        $group = [];
        $inGroup = false;

        foreach ($document->getElementsByTagName('meta') as $element) {
            $key = strtolower(trim($element->getAttribute('property')));
            $content = trim($element->getAttribute('content'));

            if ($content === '') {
                continue;
            }

            // `og:image:url` is defined as identical to `og:image`, so it opens an image object too,
            // and a second root means the first group is complete.
            if ($key === 'og:image' || $key === 'og:image:url') {
                if ($inGroup) {
                    break;
                }

                $inGroup = true;
                $group[$key] = $content;

                continue;
            }

            // A structured property belongs to the root that precedes it; one appearing before any
            // root belongs to nothing and is ignored rather than adopted.
            if ($key === 'og:image:secure_url' && $inGroup) {
                $group[$key] ??= $content;
            }
        }

        return $group['og:image:secure_url']
            ?? $group['og:image']
            ?? $group['og:image:url']
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

    /**
     * The document's declared base for relative references, if it sets a usable one.
     *
     * The document base is the first `<base>` that carries an href — not the first `<base>` element,
     * which may set only `target` and leave the href to a later one.
     */
    private function baseHref(DOMDocument $document, string $url): ?string
    {
        foreach ($document->getElementsByTagName('base') as $element) {
            $href = trim($element->getAttribute('href'));

            if ($href !== '') {
                return LinkUrl::resolve($href, $url);
            }
        }

        return null;
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
