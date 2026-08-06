<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;

/**
 * Builds the HTTP client SafeHttpFetcher runs on.
 *
 * This lives inside App\Outbound rather than in the service provider so that constructing an HTTP
 * client stays something only the outbound seam does — the egress architecture test enforces exactly
 * that, and a provider exempted from it would be a standing hole in the rule.
 *
 * The handler is an explicit CurlHandler. Guzzle otherwise picks one at runtime and falls back to
 * the PHP stream handler when ext-curl is absent, where every CURLOPT_* — including the connection
 * pin the SSRF guard depends on — is silently ignored while requests keep succeeding.
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
            // SafeHttpFetcher also sets CURLOPT_NOPROXY per request; stated here too so the client
            // is not proxy-capable by construction. A proxy resolves the destination itself, which
            // is the step the pin exists to control.
            'proxy' => '',
        ]);
    }

    /**
     * Refuse to build a client that cannot pin its connections.
     *
     * `composer.json` requiring ext-curl does not settle this: it says nothing about the libcurl
     * the extension was built against, and CURLOPT_CONNECT_TO only exists from 7.49.0. On an older
     * one the option is accepted and ignored, so requests would resolve through the system resolver
     * — and the after-the-fact peer check would not notice, because that resolver usually returns
     * the very addresses the guard just validated. The gap only opens when someone controls the
     * answer, which is exactly the case being defended against.
     *
     * Checked here rather than at boot so a site that never fetches anything is never affected.
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
