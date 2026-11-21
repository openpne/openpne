<?php

declare(strict_types=1);

namespace App\SiteCheck;

use App\Outbound\CappedStream;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Fetches an address the operator gave this site, APP_URL or openpne:check's --parent-url, never one
 * a member typed, which is why it bypasses App\Outbound (docs/internals/outbound-http.md).
 */
final class SiteFetcher
{
    private const TIMEOUT_SECONDS = 10;

    public const MAX_BYTES = 64 * 1024;

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): FetchedPage
    {
        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withMiddleware(self::cappedBody())
                ->withHeaders($headers)
                ->get($url);
        } catch (ConnectionException $e) {
            return new FetchedPage(null, '', null, $e->getMessage());
        }

        return new FetchedPage($response->status(), $response->body(), $response->header('Location') ?: null);
    }

    /** A transfer cut at the cap is handed on with its status and first bytes, which is all a verdict reads. */
    public static function cappedBody(): callable
    {
        return static fn (callable $next): callable => static function (RequestInterface $request, array $options) use ($next): PromiseInterface {
            $sink = new CappedStream(Utils::streamFor(''), self::MAX_BYTES);
            $options[RequestOptions::SINK] = $sink;

            return $next($request, $options)->otherwise(static function (mixed $reason) use ($request, $sink): mixed {
                if ($reason instanceof ResponseException && $sink->wasCapped()) {
                    $sink->rewind();

                    return $reason->getResponse()->withBody($sink);
                }

                // Laravel throws a cut 4xx or 5xx carrying its response as a RequestException, not a ConnectionException.
                return Create::rejectionFor($reason instanceof ResponseException ? new NetworkException($reason->getMessage(), $request, $reason) : $reason);
            });
        };
    }
}
