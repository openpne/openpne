<?php

declare(strict_types=1);

namespace App\LinkCard;

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
 *  - **the query is kept in full, including an empty one.** Plenty of sites put the article id
 *    there, so stripping or reordering it would merge unrelated pages into one card; and a server
 *    can distinguish `/a` from `/a?`, so the trailing `?` is kept as well. Tracking parameters
 *    therefore split the cache, which is the safe direction to be wrong in.
 *
 * http and https are deliberately *not* unified: they are different origins, and the fetcher treats
 * them as such.
 *
 * Ports are restricted to the same scheme-independent set SafeHttpFetcher will dial, 80 and 443.
 * Accepting more here would only mint cards for URLs the fetcher then refuses, leaving permanently
 * failed rows behind. A legal non-default combination (https on 80) keeps its port, since that is
 * part of the address.
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

        // An IPv6 literal in its RFC 5952 form, which is also what resolve() returns for one, so a
        // pasted URL and a reference found inside a page do not mint two cards for one address.
        if (str_contains($host, ':') && ($packed = @inet_pton($host)) !== false) {
            $host = inet_ntop($packed);
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
