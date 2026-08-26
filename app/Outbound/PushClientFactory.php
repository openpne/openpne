<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Builds the HTTP client web push is delivered on.
 *
 * The channel package would otherwise build it from `webpush.client_options` itself, and on this
 * Laravel version it builds that client through `Http::…->buildClient()`, whose `createClient()`
 * passes only a handler stack — the option bag is dropped on the floor. Every option this app sets
 * there would silently stop applying. So the client is built here instead, which is also where the
 * egress boundary test expects an HTTP client to be constructed.
 *
 * `proxy` and `allow_redirects` are applied after the caller's options rather than before them:
 * they are what keeps a validated https endpoint from becoming a request somewhere else, so config
 * may tighten this transport but never open it. The timeouts are defaults the config owns, because
 * the bound they belong to is arithmetic the config states (see config/webpush.php).
 *
 * This is not SafeHttpFetcher's client: no address is validated and no connection is pinned, which
 * is the documented weakness of this seam (docs/internals/outbound-http.md). CurlClientFactory is
 * deliberately not reused — it refuses to build at all without libcurl 7.49, a requirement that
 * exists for a pin this path does not make, and importing it would newly break push on builds that
 * work today.
 *
 * Only WebPush::flush() runs on this client. flushPooled() takes a separate async client that the
 * library discovers on its own — with no options at all — and no HTTPlug adapter is installed, so
 * today it throws instead. Installing one would grow an egress path with none of the above.
 */
final class PushClientFactory
{
    /** @param  array<string, mixed>  $options */
    public function make(array $options): ClientInterface
    {
        return new Client([
            'timeout' => 30,
            ...$options,
            'allow_redirects' => false,
            'proxy' => '',
        ]);
    }
}
