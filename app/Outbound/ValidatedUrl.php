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
     * Caller preconditions: `$url` carries `$host` verbatim so the pin can match, `$host` is Punycode
     * without a trailing dot, `$port` is 80 or 443, and `$pinnedAddress` is one of `$addresses`, all
     * validated as global.
     *
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $pinnedAddress,
        public array $addresses,
    ) {}

    /**
     * The `CURLOPT_CONNECT_TO` entry, `HOST:PORT:CONNECT-TO-HOST:CONNECT-TO-PORT` with an IPv6
     * literal bracketed. Unlike `CURLOPT_RESOLVE` it leaves the Host header and TLS SNI as the URL's
     * own host and does not seed libcurl's DNS cache, so the override cannot leak into a later
     * request on the same handle.
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
