<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;

/**
 * Lives inside `App\Outbound` so that constructing an HTTP client stays something only the outbound
 * seam does, which the egress architecture test enforces. The handler is an explicit `CurlHandler`:
 * with Guzzle's stream-handler fallback every `CURLOPT_*`, the connection pin included, is silently
 * ignored while requests keep succeeding.
 */
final class CurlClientFactory
{
    /** CURLOPT_CONNECT_TO was added in libcurl 7.49.0. */
    public const MIN_LIBCURL = 0x073100;

    /** @param  int  $minLibcurl  Overridable so the refusal path is testable on a build that satisfies it. */
    public function __construct(private readonly int $minLibcurl = self::MIN_LIBCURL) {}

    public function make(): ClientInterface
    {
        $this->assertPinnableTransport();

        return new Client([
            'handler' => HandlerStack::create(new CurlHandler),
            // Fixed here as well as per request in `SafeHttpFetcher`, so the client is not
            // proxy-capable by construction; Guzzle pins the empty value as `CURLOPT_PROXY` with no
            // fallback to the environment.
            'proxy' => '',
        ]);
    }

    /**
     * ext-curl alone does not settle this: `CURLOPT_CONNECT_TO` exists only from libcurl 7.49.0, and
     * an older build accepts and ignores it, which the after-the-fact peer check cannot notice
     * because the system resolver normally returns the very addresses the guard validated. Checked
     * here rather than at boot so a site that never fetches anything is never affected.
     *
     * @throws OutboundException
     */
    private function assertPinnableTransport(): void
    {
        if (! extension_loaded('curl') || ! defined('CURLOPT_CONNECT_TO')) {
            throw OutboundException::blocked('Outbound HTTP needs ext-curl; without it connections cannot be pinned to a validated address.');
        }

        $version = curl_version();

        if (($version['version_number'] ?? 0) < $this->minLibcurl) {
            throw OutboundException::blocked(sprintf(
                'Outbound HTTP needs libcurl 7.49.0 or newer for CURLOPT_CONNECT_TO; this build has %s.',
                $version['version'] ?? 'an unknown version',
            ));
        }
    }
}
