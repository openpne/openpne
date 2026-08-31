<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

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
 * those two are what keeps a validated https endpoint from becoming a request somewhere else, and
 * an absent `proxy` is not a neutral default but the environment's. The response sink is pinned the
 * same way, by a handler on the stack: the channel reads a response's status and nothing else, so
 * the body is kept only up to MAX_RESPONSE_BYTES and the transfer aborted past it — without that, a
 * member-supplied endpoint answering at length would be buffered whole into the worker. Nothing
 * else here is pinned — the rest of the option bag is the config's to set.
 *
 * The timeout is the config's to set too, but its fallback is not a free choice: it is what applies
 * on a site that deleted the key, and the transport sends one device after another, so a generous
 * default silently multiplies into a job that outlives the queue's reservation. It has to satisfy
 * the same bound the configured value does, and WebPushTimeoutBudgetTest builds a client to check
 * the value that actually applies rather than the constant behind it.
 *
 * This is not SafeHttpFetcher's client: no address is validated and no connection is pinned, which
 * is the documented weakness of this seam (docs/internals/outbound-http.md). CurlClientFactory is
 * deliberately not reused — it refuses to build at all without libcurl 7.49, a requirement that
 * exists for a pin this path does not make, and importing it would newly break push on builds that
 * work today.
 *
 * Only WebPush::flush() runs on this client. flushPooled() takes a separate async client that the
 * library discovers on its own — with no options at all — and no HTTPlug adapter is installed, so
 * today it throws instead. Installing one would grow an egress path with none of the above, which
 * is why WebPushUpstreamAssumptionsTest fails the moment one becomes discoverable.
 */
final class PushClientFactory
{
    /** Applies when `webpush.client_options` states none; see the note above on why it is not 30. */
    private const FALLBACK_TIMEOUT = 5;

    /**
     * A push service answers with an empty body or a few lines of JSON, so this is headroom, not a
     * budget: an endpoint that answers with more is not a push service.
     */
    public const MAX_RESPONSE_BYTES = 16 * 1024;

    /** @param  array<string, mixed>  $options */
    public function make(array $options): ClientInterface
    {
        $handler = $options['handler'] ?? null;
        $stack = $handler instanceof HandlerStack ? $handler : HandlerStack::create($handler);
        $stack->push(self::boundResponseBody(), 'bound_response_body');

        return new Client([
            'timeout' => self::FALLBACK_TIMEOUT,
            ...$options,
            'handler' => $stack,
            'allow_redirects' => false,
            'proxy' => '',
        ]);
    }

    /**
     * A fresh sink per request, not one on the client: a client-level sink is a single stream every
     * response would be appended to. CappedStream's short write is what makes libcurl abort.
     */
    private static function boundResponseBody(): callable
    {
        return static fn (callable $next): callable => static function (RequestInterface $request, array $options) use ($next) {
            $options[RequestOptions::SINK] = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), self::MAX_RESPONSE_BYTES);

            return $next($request, $options);
        };
    }
}
