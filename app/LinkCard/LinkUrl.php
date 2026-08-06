<?php

declare(strict_types=1);

namespace App\LinkCard;

use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Normalises a URL into the key a card is stored under, and resolves references found inside a page.
 *
 * The point is to make two spellings of the same page share one card, without ever making two
 * different pages collide. That asymmetry decides what is normalised and what is left alone:
 *
 *  - scheme and host lowercase, default port dropped, trailing dot removed, IDN to Punycode —
 *    these cannot change which resource is addressed;
 *  - the fragment is dropped, since it never reaches the server;
 *  - **the query is kept in full.** Plenty of sites put the article id there, so stripping or
 *    reordering it would merge unrelated pages into one card. Tracking parameters therefore split
 *    the cache, which is the safe direction to be wrong in.
 *
 * http and https are deliberately *not* unified: they are different origins, and the fetcher treats
 * them as such.
 */
final class LinkUrl
{
    /** Longer than this is refused outright rather than hashed — see SafeHttpFetcher's own cap. */
    private const MAX_LENGTH = 2048;

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

        $port = $parts['port'] ?? null;
        $isDefaultPort = $port === null || ($scheme === 'https' ? $port === 443 : $port === 80);

        $normalized = $scheme.'://'.$host;

        if (! $isDefaultPort) {
            $normalized .= ':'.$port;
        }

        $normalized .= $parts['path'] ?? '';

        if (isset($parts['query']) && $parts['query'] !== '') {
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
     * $reference resolved against $base, per RFC 3986, or null when the result is not http(s).
     *
     * $base must be the URL of the *response* the reference was found in, not the URL that was
     * requested: after a redirect crosses hosts, `/thumb.jpg` on the page we arrived at is a
     * different file from the same path on the page we asked for.
     *
     * Purely textual — nothing here resolves a name or opens a connection. Whether the result may be
     * fetched is SafeHttpFetcher's decision, made when it is fetched.
     */
    public static function resolve(?string $reference, string $base): ?string
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        try {
            $resolved = UriResolver::resolve(new Uri($base), new Uri(trim($reference)));
        } catch (MalformedUriException) {
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
