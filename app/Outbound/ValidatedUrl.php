<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * A URL that has passed every check and the address the connection will be pinned to.
 *
 * Producing one of these is the only way to reach SafeHttpFetcher's request step, so a URL cannot
 * be dialled without having been validated — including on a redirect, which re-enters validation
 * rather than being followed by libcurl.
 */
final readonly class ValidatedUrl
{
    /**
     * @param  string  $url  Absolute, normalised, http(s), no userinfo. Its host is $host verbatim —
     *                       see SafeHttpFetcher::validate(), which rewrites it so the pin can match.
     * @param  string  $host  Punycode host, no trailing dot.
     * @param  int  $port  80 or 443.
     * @param  string  $pinnedAddress  A validated address from $addresses; the one dialled.
     * @param  list<string>  $addresses  Every address the host resolved to, all validated as global.
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $pinnedAddress,
        public array $addresses,
    ) {}

    /**
     * The libcurl CURLOPT_CONNECT_TO entry that sends this request to the validated address.
     *
     * Format is HOST:PORT:CONNECT-TO-HOST:CONNECT-TO-PORT, with an IPv6 literal bracketed. Unlike
     * CURLOPT_RESOLVE this leaves the Host header and the TLS SNI as the URL's own host and does not
     * seed libcurl's DNS cache, so the override cannot leak into a later request on the same handle.
     */
    public function connectTo(): string
    {
        return sprintf('%s:%d:%s:%d', $this->bracketed($this->host), $this->port, $this->bracketed($this->pinnedAddress), $this->port);
    }

    /** Whether $address is one of the addresses this URL was validated against. */
    public function permits(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        foreach ($this->addresses as $candidate) {
            if (@inet_pton($candidate) === $packed) {
                return true;
            }
        }

        return false;
    }

    private function bracketed(string $host): string
    {
        return str_contains($host, ':') ? "[{$host}]" : $host;
    }
}
