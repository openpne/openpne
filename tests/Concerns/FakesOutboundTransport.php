<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Outbound\HostResolver;
use App\Outbound\PublicIpGuard;
use App\Outbound\SafeHttpFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;

/**
 * Substituted beneath a real SafeHttpFetcher (the HTTP handler and the DNS resolver), so the
 * destination check, the connection pin, the byte cap and the media-type rules run on every call. The
 * transport reports the pinned address as the peer by default; a fake reporting no peer would send
 * every test down the unverified path.
 */
trait FakesOutboundTransport
{
    /** @var array<string, list<string>> host => addresses */
    private array $fakeDns = [];

    /** @var list<array{Response|\Throwable, string|null}> */
    private array $fakeResponses = [];

    /** @var list<RequestInterface> */
    protected array $outboundRequests = [];

    /** Answer $host with $addresses. A host with no entry does not resolve, and the fetch fails. */
    protected function resolvesTo(string $host, array $addresses): void
    {
        $this->fakeDns[$host] = $addresses;
    }

    /** Queue the next response. Responses are consumed in order, one per request. */
    protected function queueResponse(Response|\Throwable $response, ?string $connectedTo = null): void
    {
        $this->fakeResponses[] = [$response, $connectedTo];
    }

    protected function queueHtml(string $html, string $contentType = 'text/html; charset=UTF-8'): void
    {
        $this->queueResponse(new Response(200, ['Content-Type' => $contentType], $html));
    }

    protected function queueJson(array $payload): void
    {
        $this->queueResponse(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload)));
    }

    protected function queueBinary(string $bytes, string $contentType): void
    {
        $this->queueResponse(new Response(200, ['Content-Type' => $contentType], $bytes));
    }

    protected function fakeFetcher(): SafeHttpFetcher
    {
        $handler = function (RequestInterface $request, array $options) {
            $this->outboundRequests[] = $request;

            [$response, $connectedTo] = array_shift($this->fakeResponses) ?? [new Response(404, [], ''), null];

            if ($response instanceof \Throwable) {
                // Guzzle reports a failed transfer through on_stats before rejecting.
                if (isset($options['on_stats'])) {
                    $partial = $response instanceof ResponseException ? $response->getResponse() : null;
                    $options['on_stats'](new TransferStats($request, $partial, 0.0, null, []));
                }

                return Create::rejectionFor($response);
            }

            $capped = false;

            if (isset($options['sink'])) {
                $options['sink']->write((string) $response->getBody());
                $capped = $options['sink']->wasCapped();
                $response = $response->withBody($options['sink']);
            }

            if (isset($options['on_stats'])) {
                // An aborted write reports CURLE_WRITE_ERROR (23) as the handler error data, as libcurl does.
                $options['on_stats'](new TransferStats($request, $response, 0.0, $capped ? 23 : 0, [
                    'primary_ip' => $connectedTo ?? $this->pinnedAddress($options),
                ]));
            }

            // libcurl aborts on a short write, which Guzzle surfaces as a ResponseException carrying
            // the response built so far.
            if ($capped) {
                return Create::rejectionFor(new ResponseException('Unable to write to stream', $request, $response));
            }

            return Create::promiseFor($response);
        };

        return new SafeHttpFetcher(
            client: new Client(['handler' => HandlerStack::create($handler)]),
            resolver: new class($this->fakeDns) implements HostResolver
            {
                public function __construct(private readonly array $dns) {}

                public function resolve(string $host): array
                {
                    return $this->dns[$host] ?? [];
                }
            },
            guard: new PublicIpGuard,
            userAgent: 'OpenPNE/4 (+https://sns.example.com)',
            connectTimeout: 3,
            requestTimeout: 8,
            fetchTimeout: 10,
            maxRedirects: 3,
        );
    }

    /** The address the fetcher pinned to, read back out of the curl option it set. */
    private function pinnedAddress(array $options): string
    {
        $entry = $options['curl'][CURLOPT_CONNECT_TO][0] ?? '';

        if (preg_match('/^(?:\[[^\]]+\]|[^:]+):\d+:(\[[^\]]+\]|[^:]+):\d+$/', $entry, $matches) !== 1) {
            throw new \RuntimeException("CURLOPT_CONNECT_TO entry [{$entry}] is not in HOST:PORT:ADDRESS:PORT form.");
        }

        return trim($matches[1], '[]');
    }
}
