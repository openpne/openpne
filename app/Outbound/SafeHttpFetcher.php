<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;

/**
 * The one place this app fetches a URL a member supplied. Everything outbound goes through here.
 *
 * The threat is SSRF: a member pastes a URL, the server dereferences it, and the response (or merely
 * the attempt) reaches somewhere only the server can go — a metadata endpoint, an admin port on
 * localhost, a neighbour in the private network. Validating the URL string is not enough, because
 * DNS decides where the connection lands and can answer differently between the check and the dial,
 * and because a redirect is a second URL nobody validated.
 *
 * So the contract is:
 *
 *   - every URL is validated, resolved, and had all of its addresses proven globally routable
 *     (PublicIpGuard) before a socket is opened;
 *   - the connection is PINNED to one of those addresses with CURLOPT_CONNECT_TO, so the address
 *     validated is the address dialled — a second DNS answer cannot redirect it;
 *   - redirects are not followed by libcurl. Each Location re-enters validation from the top, with
 *     its own resolution and its own pin;
 *   - no proxy, ever. A proxy resolves the destination itself, which is exactly the step the pin
 *     exists to control, so the environment variables libcurl would honour are disabled explicitly.
 *
 * The pin only works with the curl handler: with Guzzle's PHP stream fallback every CURLOPT_* is
 * ignored and requests would go wherever the system resolver points. The client is therefore built
 * on an explicit CurlHandler (OutboundServiceProvider) and composer.json requires ext-curl, so an
 * install without it fails loudly instead of running with the guard silently disarmed.
 *
 * Requests carry no cookies, no credentials and no Referer, and TLS verification is not
 * configurable off. See docs/internals/outbound-http.md.
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
            $response = $this->send($target, $maxBytes, $deadline);

            $location = $this->redirectTarget($response, $target);

            if ($location === null) {
                return $this->toFetchedResponse($target, $response, $maxBytes);
            }

            $current = $location;
        }

        throw OutboundException::blocked("More than {$this->maxRedirects} redirects from [{$url}].");
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
            throw OutboundException::blocked("Not an absolute URL: [{$url}].");
        }

        // Checked before the host, so `file:///etc/passwd` — which parses with no host at all — is
        // reported as the refused scheme it is rather than as a malformed URL.
        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw OutboundException::blocked("Refused scheme [{$scheme}].");
        }

        if (! isset($parts['host'])) {
            throw OutboundException::blocked("Not an absolute URL: [{$url}].");
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
        $addresses = $this->addressesFor($host);

        // The URL is rewritten to carry the canonical host, because CURLOPT_CONNECT_TO matches on the
        // host as it appears in the request URL. Leave a Unicode or trailing-dot host in place and the
        // pin quietly fails to match, dropping the request back to whatever the system resolver says
        // — the guard would still have run, but on an address nobody dialled.
        // An IPv6 literal goes back in bracketed; the bare form is not a valid URI host.
        $canonical = (string) (new Uri($url))->withHost(str_contains($host, ':') ? "[{$host}]" : $host);

        return new ValidatedUrl($canonical, $host, $port, $addresses[0], $addresses);
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
     * @throws OutboundException
     */
    private function send(ValidatedUrl $target, int $maxBytes, float $deadline): ResponseInterface
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw OutboundException::failed('Ran out of time before the request could be sent.');
        }

        $sink = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), $maxBytes);
        $connected = null;

        try {
            $response = $this->client->request('GET', $target->url, [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::COOKIES => false,
                RequestOptions::VERIFY => true,
                RequestOptions::SINK => $sink,
                RequestOptions::CONNECT_TIMEOUT => min($this->connectTimeout, $remaining),
                RequestOptions::TIMEOUT => min($this->requestTimeout, $remaining),
                RequestOptions::ON_STATS => function (TransferStats $stats) use (&$connected): void {
                    $ip = $stats->getHandlerStats()['primary_ip'] ?? null;
                    $connected = is_string($ip) && $ip !== '' ? $ip : null;
                },
                RequestOptions::HEADERS => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,image/*;q=0.8,*/*;q=0.5',
                ],
                'curl' => [
                    CURLOPT_CONNECT_TO => [$target->connectTo()],
                    // libcurl reads http_proxy/HTTPS_PROXY/ALL_PROXY from the environment on its own.
                    // A proxy resolves the destination itself, so it would silently undo the pin.
                    CURLOPT_PROXY => '',
                    CURLOPT_NOPROXY => '*',
                    // Nothing outbound should ever present credentials, from a URL or from ~/.netrc.
                    CURLOPT_NETRC => CURL_NETRC_IGNORED,
                    CURLOPT_UNRESTRICTED_AUTH => false,
                ],
            ]);
        } catch (GuzzleException $e) {
            // A capped body aborts the transfer (CappedStream), which surfaces here as a write error.
            // The bytes already collected are the ones worth having, so that is not a failure.
            if ($sink->wasCapped()) {
                $this->assertConnectedAsPinned($target, $connected);

                return $this->cappedResponse($sink);
            }

            throw OutboundException::failed("Request to [{$target->url}] failed: {$e->getMessage()}");
        }

        $this->assertConnectedAsPinned($target, $connected);

        return $response;
    }

    /**
     * Confirm the socket actually landed on a validated address.
     *
     * The pin is a libcurl feature reached through two layers of configuration, and its failure mode
     * is silence: a mismatched CURLOPT_CONNECT_TO entry, or a handler that ignores curl options
     * altogether, leaves the request working perfectly while going wherever DNS says. Reading back
     * the peer address turns that from an invisible hole into a failed fetch.
     *
     * @throws OutboundException
     */
    private function assertConnectedAsPinned(ValidatedUrl $target, ?string $connected): void
    {
        if ($connected !== null && ! $target->permits($connected)) {
            throw OutboundException::blocked("Connected to [{$connected}], which is not an address [{$target->host}] was validated against.");
        }
    }

    /**
     * The absolute URL of the redirect this response asks for, or null when it is not a redirect.
     *
     * A relative Location is resolved against the URL that produced it, per RFC 7231. The result is
     * only a candidate: the caller feeds it back through validate(), so a redirect to a private
     * address is refused exactly as a pasted one would be.
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

        return (string) UriResolver::resolve(new Uri($from->url), new Uri($location));
    }

    private function toFetchedResponse(ValidatedUrl $target, ResponseInterface $response, int $maxBytes): FetchedResponse
    {
        $body = (string) $response->getBody();

        return new FetchedResponse(
            url: $target->url,
            status: $response->getStatusCode(),
            contentType: $response->getHeaderLine('Content-Type'),
            body: $body,
            truncated: strlen($body) >= $maxBytes,
        );
    }

    /**
     * Rebuild a response from a transfer the sink cut short.
     *
     * Guzzle raises before it can hand back a response object, so the collected prefix is recovered
     * from the sink. The status and headers are lost with it; the caller sees a 200 with no
     * Content-Type, which the HTML path handles (it sniffs the charset from the markup) and the
     * image path rejects (a truncated image is not decodable anyway).
     */
    private function cappedResponse(CappedStream $sink): ResponseInterface
    {
        $sink->rewind();

        return new Response(200, [], $sink->getContents());
    }
}
