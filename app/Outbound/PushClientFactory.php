<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Builds the HTTP client web push is delivered on; it validates no address and pins no connection,
 * which is the documented weakness of this seam (docs/internals/outbound-http.md).
 * `CurlClientFactory` is deliberately not reused: its libcurl 7.49 refusal guards a pin this path
 * does not make and would newly break push on builds that work today.
 */
final class PushClientFactory
{
    /**
     * Applies when `webpush.client_options` states no timeout; the transport sends devices one after
     * another, so it must satisfy the same job bound a configured value does.
     */
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
        // Cloned, not pushed onto: the channel's provider builds a client per resolve from one
        // captured option bag, and a shared stack would gain a copy of this handler each time.
        $stack = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);
        $stack->push(self::boundResponseBody(), 'bound_response_body');

        return new Client([
            'timeout' => self::FALLBACK_TIMEOUT,
            ...$options,
            'handler' => $stack,
            // After the caller's options, so a site deleting them cannot make `proxy` the environment's.
            'allow_redirects' => false,
            'proxy' => '',
        ]);
    }

    /**
     * A fresh sink per request, not one on the client: a client-level sink is a single stream every
     * response would be appended to. A capped transfer is handed on as the response libcurl had
     * already built, since the status is what retires a dead device; any other failure stays one.
     */
    private static function boundResponseBody(): callable
    {
        return static fn (callable $next): callable => static function (RequestInterface $request, array $options) use ($next): PromiseInterface {
            $sink = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), self::MAX_RESPONSE_BYTES);
            $options[RequestOptions::SINK] = $sink;

            return $next($request, $options)->otherwise(static function (mixed $reason) use ($sink): mixed {
                if ($reason instanceof ResponseException && $sink->wasCapped()) {
                    $sink->rewind();

                    return $reason->getResponse()->withBody($sink);
                }

                return Create::rejectionFor($reason);
            });
        };
    }
}
