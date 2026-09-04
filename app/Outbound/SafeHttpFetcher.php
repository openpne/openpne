<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;

/**
 * The one place this app fetches a member-supplied URL: every hop is validated, every resolved
 * address proven global, and the connection pinned to one of them with `CURLOPT_CONNECT_TO`, so
 * redirects re-enter validation instead of being followed by libcurl. No proxy, cookies,
 * credentials or Referer, and TLS verification is not configurable off; see
 * docs/internals/outbound-http.md.
 */
final class SafeHttpFetcher
{
    private const MAX_URL_LENGTH = 2048;

    private const ALLOWED_PORTS = [80, 443];

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly HostResolver $resolver,
        private readonly PublicIpGuard $guard,
        private readonly string $userAgent,
        private readonly int $connectTimeout,
        private readonly int $requestTimeout,
        private readonly int $fetchTimeout,
        private readonly int $maxRedirects,
    ) {}

    /**
     * GET $url, following redirects under the guard, and read at most $maxBytes of decoded body.
     *
     * @param  float|null  $deadline  A microtime(true) instant this fetch must finish by, so a caller
     *                                running several fetches can budget the whole job rather than
     *                                letting each one spend its own timeout.
     *
     * @throws OutboundException
     */
    public function get(string $url, int $maxBytes, ?float $deadline = null): FetchedResponse
    {
        $deadline = min($deadline ?? PHP_FLOAT_MAX, microtime(true) + $this->fetchTimeout);
        $current = $url;

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            $target = $this->validate($current);
            [$response, $capped] = $this->send($target, $maxBytes, $deadline);

            $location = $this->redirectTarget($response, $target);

            if ($location === null) {
                return new FetchedResponse(
                    url: $target->url,
                    status: $response->getStatusCode(),
                    contentType: $response->getHeaderLine('Content-Type'),
                    body: (string) $response->getBody(),
                    truncated: $capped,
                );
            }

            $current = $location;
        }

        throw OutboundException::blocked(sprintf('More than %d redirects from [%s].', $this->maxRedirects, self::describe($url)));
    }

    /**
     * The underlying message is never repeated and the previous exception is dropped: Guzzle 8
     * redacts the request URI but the wording is upstream's to change (Guzzle 7 appended it), and a
     * logged exception prints its whole chain. What survives is the exception class and the curl
     * errno, enough to tell a DNS failure from a TLS one.
     */
    private static function transportFailure(ValidatedUrl $target, GuzzleException $e, ?int $errno): OutboundException
    {
        $reason = (new ReflectionClass($e))->getShortName();

        if ($errno !== null && $errno !== 0) {
            $reason .= ", curl errno {$errno}";
        }

        return OutboundException::failed(sprintf('Request to [%s] failed (%s).', self::describe($target->url), $reason));
    }

    /**
     * A URL reduced to what is safe to put in an exception message or a log line.
     *
     * The query string is where a member-pasted URL carries its secrets — a signed download link, a
     * one-time token — and an exception from a queued job ends up in the log verbatim.
     */
    private static function describe(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '(unparseable URL)';
        }

        return sprintf('%s://%s%s', $parts['scheme'] ?? 'http', $parts['host'], $parts['path'] ?? '');
    }

    /**
     * Check a URL and resolve it to an address we may dial.
     *
     * @throws OutboundException
     */
    public function validate(string $url): ValidatedUrl
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw OutboundException::blocked('URL exceeds the length limit.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw OutboundException::blocked('Not an absolute URL: ['.self::describe($url).'].');
        }

        // Checked before the host, so `file:///etc/passwd` — which parses with no host at all — is
        // reported as the refused scheme it is rather than as a malformed URL.
        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw OutboundException::blocked("Refused scheme [{$scheme}].");
        }

        if (! isset($parts['host'])) {
            throw OutboundException::blocked('Not an absolute URL: ['.self::describe($url).'].');
        }

        // A credential in the URL is never something we should replay outbound, and `user@host` is a
        // classic way to make a URL read as one host while addressing another.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw OutboundException::blocked('URL carries userinfo.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw OutboundException::blocked("Refused port [{$port}].");
        }

        $host = $this->canonicalHost($parts['host']);

        // Rewritten to carry the canonical host (an IPv6 literal bracketed): `CURLOPT_CONNECT_TO`
        // matches on the host as the request URL spells it, and a mismatch silently drops the
        // request back to the system resolver.
        try {
            $uri = (new Uri($url))->withHost(str_contains($host, ':') ? "[{$host}]" : $host);
        } catch (\InvalidArgumentException) {
            // Caught as the bare `InvalidArgumentException`, which the parser's `MalformedUriException`
            // extends and `withHost()` throws directly, so a URL the parser refuses is reported
            // malformed before the resolver sees it.
            throw OutboundException::blocked('Malformed URL: ['.self::describe($url).'].');
        }

        // The host as the URI now spells it — an IPv6 literal in its canonical form — so the pin
        // entry names the same string the request URL carries.
        $host = trim($uri->getHost(), '[]');
        $addresses = $this->addressesFor($host);

        return new ValidatedUrl((string) $uri, $host, $port, $addresses[0], $addresses);
    }

    /**
     * Normalise the host to the form the guard and the pin both use.
     *
     * The trailing dot of a fully-qualified name resolves identically but compares unequal, and a
     * Unicode host must reach libcurl as Punycode; leaving either alone makes the validated host and
     * the dialled host different strings.
     *
     * @throws OutboundException
     */
    private function canonicalHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if ($host === '') {
            throw OutboundException::blocked('URL has an empty host.');
        }

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                throw OutboundException::blocked('Host is not a valid internationalised name.');
            }

            $host = $ascii;
        }

        return $host;
    }

    /**
     * Every address the host will be reached at, each proven globally routable.
     *
     * All of them are checked, not just the one dialled: a name that answers with a public and a
     * private address is answering with a private address, and which one arrives first is the
     * attacker's choice.
     *
     * @return non-empty-list<string>
     *
     * @throws OutboundException
     */
    private function addressesFor(string $host): array
    {
        // An address literal is dialled as written, so it is judged directly rather than resolved.
        $literal = @inet_pton($host);

        if ($literal !== false) {
            if (! $this->guard->isGlobal($host)) {
                throw OutboundException::blocked("Refused address [{$host}].");
            }

            return [$host];
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw OutboundException::failed("Host [{$host}] did not resolve.");
        }

        foreach ($addresses as $address) {
            if (! $this->guard->isGlobal($address)) {
                throw OutboundException::blocked("Host [{$host}] resolves to the non-global address [{$address}].");
            }
        }

        return array_values($addresses);
    }

    /**
     * @return array{ResponseInterface, bool} the response and whether the body hit the read cap
     *
     * @throws OutboundException
     */
    private function send(ValidatedUrl $target, int $maxBytes, float $deadline): array
    {
        $remaining = $deadline - microtime(true);

        // Below a millisecond Guzzle rejects the timeout as invalid rather than treating it as none,
        // and either way the request could not complete: report it as the time having run out.
        if ($remaining < 0.001) {
            throw OutboundException::failed('Ran out of time before the request could be sent.');
        }

        $sink = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), $maxBytes);
        $connected = null;
        $errno = null;

        try {
            $response = $this->client->request('GET', $target->url, [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::COOKIES => false,
                RequestOptions::VERIFY => true,
                // Final: Guzzle pins `CURLOPT_PROXY` to '' itself, so libcurl never reads the proxy
                // environment variables that would let a proxy resolve the destination and undo the pin.
                RequestOptions::PROXY => '',
                RequestOptions::SINK => $sink,
                RequestOptions::CONNECT_TIMEOUT => min($this->connectTimeout, $remaining),
                RequestOptions::TIMEOUT => min($this->requestTimeout, $remaining),
                RequestOptions::ON_STATS => function (TransferStats $stats) use (&$connected, &$errno): void {
                    $ip = $stats->getHandlerStats()['primary_ip'] ?? null;
                    $connected = is_string($ip) && $ip !== '' ? $ip : null;
                    // The curl errno of a failed transfer: Guzzle reports it here, before rejecting.
                    $data = $stats->getHandlerErrorData();
                    $errno = is_int($data) ? $data : null;
                },
                RequestOptions::HEADERS => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,image/*;q=0.8,*/*;q=0.5',
                ],
                // The pin, and nothing else: Guzzle 8's curl handler refuses the proxy, redirect,
                // sink and timeout options from this array, so those are first-class options above
                // and cannot be undone here.
                'curl' => [
                    CURLOPT_CONNECT_TO => [$target->connectTo()],
                ],
            ]);
        } catch (ResponseException $e) {
            // A capped body aborts the transfer, which surfaces as a write error carrying the
            // response Guzzle had already built; the collected prefix is kept with its real status
            // and Content-Type rather than a synthetic 200.
            if ($sink->wasCapped()) {
                $this->assertConnectedAsPinned($target, $connected);
                $sink->rewind();

                return [$e->getResponse()->withBody($sink), true];
            }

            throw self::transportFailure($target, $e, $errno);
        } catch (GuzzleException $e) {
            throw self::transportFailure($target, $e, $errno);
        }

        $this->assertConnectedAsPinned($target, $connected);

        return [$response, $sink->wasCapped()];
    }

    /**
     * The pin's failure mode is silence: a mismatched `CURLOPT_CONNECT_TO` entry or a handler that
     * ignores curl options leaves the request working while going wherever DNS says, so the peer
     * address is read back. A transport that reports no peer is refused too: it is in the same
     * evidential position as a wrong address.
     *
     * @throws OutboundException
     */
    private function assertConnectedAsPinned(ValidatedUrl $target, ?string $connected): void
    {
        if ($connected === null) {
            throw OutboundException::blocked("The transport did not report which address [{$target->host}] was reached at, so the connection cannot be confirmed as pinned.");
        }

        if (! $target->permits($connected)) {
            throw OutboundException::blocked("Connected to [{$connected}], which is not an address [{$target->host}] was validated against.");
        }
    }

    /**
     * The absolute URL of the redirect this response asks for, or null when it is not one; a
     * relative Location is resolved against the URL that issued it (RFC 7231). The result is a
     * candidate only: the caller feeds it back through validate().
     */
    private function redirectTarget(ResponseInterface $response, ValidatedUrl $from): ?string
    {
        if (! in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true)) {
            return null;
        }

        $location = trim($response->getHeaderLine('Location'));

        if ($location === '' || strlen($location) > self::MAX_URL_LENGTH) {
            return null;
        }

        try {
            return (string) UriResolver::resolve(new Uri($from->url), new Uri($location));
        } catch (\InvalidArgumentException) {
            // Caught as the bare `InvalidArgumentException`, which the parser's `MalformedUriException`
            // extends and the URI mutators throw directly, so `OutboundException` stays this class's
            // whole contract.
            throw OutboundException::blocked('Redirect from ['.self::describe($from->url).'] carries a malformed Location.');
        }
    }
}
