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
    public function make(): ClientInterface
    {
        return new Client([
            'handler' => HandlerStack::create(new CurlHandler),
            // SafeHttpFetcher also sets CURLOPT_NOPROXY per request; stated here too so the client
            // is not proxy-capable by construction. A proxy resolves the destination itself, which
            // is the step the pin exists to control.
            'proxy' => '',
        ]);
    }
}
