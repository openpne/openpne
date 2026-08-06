<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;

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
     * Describe a transport failure without quoting the underlying exception.
     *
     * Guzzle's own message is not safe to repeat: its curl handler appends the request URI — query
     * string and all — to the cURL error text, so sanitising only the URL this class supplies leaks
     * the secret straight back in through the concatenated half. The previous exception is dropped
     * for the same reason, since a logged exception prints its whole chain.
     *
     * What survives is the part that is diagnostic without being sensitive: the exception class and,
     * where Guzzle exposes it, the curl errno — enough to tell a DNS failure from a TLS one.
     */
    private static function transportFailure(ValidatedUrl $target, GuzzleException $e): OutboundException
    {
        $reason = (new ReflectionClass($e))->getShortName();

        if ($e instanceof RequestException || $e instanceof ConnectException) {
            $errno = $e->getHandlerContext()['errno'] ?? null;

            if (is_int($errno) && $errno !== 0) {
                $reason .= ", curl errno {$errno}";
            }
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
     * @return array{ResponseInterface, bool} the response and whether the body hit the read cap
     *
     * @throws OutboundException
     */
    private function send(ValidatedUrl $target, int $maxBytes, float $deadline): array
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
        } catch (RequestException $e) {
            // A capped body aborts the transfer (CappedStream), which surfaces here as a write error.
            // The bytes already collected are the ones worth having, so that is not a failure — and
            // Guzzle attaches the response it had already built, so the real status and Content-Type
            // survive rather than being replaced by a synthetic 200 the caller would misread.
            if ($sink->wasCapped() && $e->getResponse() !== null) {
                $this->assertConnectedAsPinned($target, $connected);
                $sink->rewind();

                return [$e->getResponse()->withBody($sink), true];
            }

            throw self::transportFailure($target, $e);
        } catch (GuzzleException $e) {
            throw self::transportFailure($target, $e);
        }

        $this->assertConnectedAsPinned($target, $connected);

        return [$response, $sink->wasCapped()];
    }

    /**
     * Confirm the socket actually landed on a validated address.
     *
     * The pin is a libcurl feature reached through two layers of configuration, and its failure mode
     * is silence: a mismatched CURLOPT_CONNECT_TO entry, or a handler that ignores curl options
     * altogether, leaves the request working perfectly while going wherever DNS says. Reading back
     * the peer address turns that from an invisible hole into a failed fetch.
     *
     * An unknown peer address is refused rather than waved through. "The transport did not tell us
     * where it connected" is the same evidential position as a bad address — a handler that reports
     * nothing is precisely one that may not have honoured the pin either.
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

        try {
            return (string) UriResolver::resolve(new Uri($from->url), new Uri($location));
        } catch (MalformedUriException) {
            // A Location this app cannot parse is not followed. Reported as blocked rather than
            // escaping as a Guzzle type, so OutboundException stays this class's whole contract.
            throw OutboundException::blocked('Redirect from ['.self::describe($from->url).'] carries a malformed Location.');
        }
    }
}
