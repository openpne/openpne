<?php

declare(strict_types=1);

namespace App\LinkCard;

use GuzzleHttp\Psr7\Rfc3986;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Two spellings of one page share a card and two different pages never collide, which decides what
 * is normalised and what is kept in full, the query included (docs/internals/link-cards.md). Ports
 * are restricted to the 80 and 443 `SafeHttpFetcher` dials, since a card minted for a port it
 * refuses could never be filled in.
 */
final class LinkUrl
{
    /** Longer than this is refused outright rather than hashed — see SafeHttpFetcher's own cap. */
    private const MAX_LENGTH = 2048;

    /** Mirrors SafeHttpFetcher::ALLOWED_PORTS, which is scheme-independent. */
    private const ALLOWED_PORTS = [80, 443];

    /** The normalised form of $url, or null when it is not a URL this app would ever fetch. */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // A URL carrying credentials is not one to store, share a cache entry for, or replay.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = self::canonicalHost($parts['host']);

        if ($host === null) {
            return null;
        }

        // The same set SafeHttpFetcher will dial, scheme-independently: http on 443 and https on 80
        // are unusual but legal, and accepting a port the fetcher then refuses would only mint cards
        // that can never be filled in.
        $port = $parts['port'] ?? null;

        if ($port !== null && ! in_array($port, self::ALLOWED_PORTS, true)) {
            return null;
        }

        $normalized = $scheme.'://'.$host;

        // The default for the scheme is dropped as redundant; a legal non-default one is part of the
        // address and stays.
        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            $normalized .= ':'.$port;
        }

        $normalized .= $parts['path'] ?? '';

        // array_key_exists, not isset: `/a?` carries an empty query and is not the same request as
        // `/a`, so the two must not share a card.
        if (array_key_exists('query', $parts)) {
            $normalized .= '?'.$parts['query'];
        }

        return $normalized;
    }

    /** The cache key for $normalizedUrl. */
    public static function hash(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    /**
     * $reference resolved against $base per RFC 3986, or null when the result is not http(s); $base
     * must be the URL of the response the reference was found in, since after a cross-host redirect
     * the same path names a different file. Purely textual: whether the result may be fetched is
     * `SafeHttpFetcher`'s decision, made when it is fetched.
     */
    public static function resolve(?string $reference, string $base): ?string
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        try {
            $resolved = UriResolver::resolve(new Uri($base), new Uri(trim($reference)));
        } catch (\InvalidArgumentException) {
            // MalformedUriException from the parser, or the bare InvalidArgumentException it extends
            // from the URI mutators resolution goes through.
            return null;
        }

        $scheme = strtolower($resolved->getScheme());

        // A `data:` or `javascript:` reference is not something to carry any further, even as text.
        if (($scheme !== 'http' && $scheme !== 'https') || $resolved->getHost() === '') {
            return null;
        }

        return (string) $resolved;
    }

    /** The host as it should be stored and dialled, or null when it is not usable. */
    private static function canonicalHost(string $host): ?string
    {
        $bracketed = str_starts_with($host, '[') && str_ends_with($host, ']');
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if ($host === '') {
            return null;
        }

        // An IPv6 literal in its RFC 5952 form by the same parser `resolve()` uses, so a pasted URL
        // and a page reference mint one card and the key does not depend on the platform's
        // `inet_pton()`.
        if (str_contains($host, ':')) {
            try {
                $host = Rfc3986::canonicalizeIpv6($host);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                return null;
            }

            $host = $ascii;
        }

        // An IPv6 literal has to go back in brackets to remain a parseable URL.
        return $bracketed || str_contains($host, ':') ? "[{$host}]" : $host;
    }
}
