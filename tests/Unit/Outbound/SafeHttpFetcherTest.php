<?php

declare(strict_types=1);

namespace Tests\Unit\Outbound;

use App\Outbound\HostResolver;
use App\Outbound\OutboundException;
use App\Outbound\PublicIpGuard;
use App\Outbound\SafeHttpFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class SafeHttpFetcherTest extends TestCase
{
    /** Marker for respondsWith(): model a transport that does not say which address it reached. */
    private const NO_PEER_REPORTED = '(none)';

    /** @var list<array{request: RequestInterface, options: array<mixed>}> */
    private array $sent = [];

    /**
     * What the fake transport answers next, one entry per request, in one of three shapes:
     * `[Response, connectedTo]` (respondsWith), `[Response, null, true]` (queueWriteAbort) or
     * `[GuzzleException, errno]` (failsWith). The first element decides which.
     *
     * @var list<array{0: Response|GuzzleException, 1: string|int|null, 2?: bool}>
     */
    private array $queue = [];

    /** @var array<string, list<string>> */
    private array $dns = [];

    /** @var list<string> hosts the fetcher asked the resolver about, in order */
    private array $resolved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $this->queue = [];
        $this->dns = [];
        $this->resolved = [];
    }

    public function test_it_fetches_a_public_url(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<title>Hi</title>'));

        $response = $this->fetcher()->get('https://example.com/page', 1024);

        $this->assertSame(200, $response->status);
        $this->assertSame('text/html', $response->mediaType());
        $this->assertSame('utf-8', $response->charset());
        $this->assertSame('<title>Hi</title>', $response->body);
        $this->assertFalse($response->truncated);
    }

    public function test_it_pins_the_connection_to_the_validated_address(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->fetcher()->get('https://example.com/page', 1024);

        $options = $this->sent[0]['options'];

        $this->assertSame(['example.com:443:93.184.216.34:443'], $options['curl'][CURLOPT_CONNECT_TO]);
        // Guzzle's curl handler allow-lists raw curl options and refuses a request carrying any other;
        // the pin is the one entry it needs, and everything else is a first-class option.
        $this->assertSame([CURLOPT_CONNECT_TO], array_keys($options['curl']));
        $this->assertSame('', $options['proxy'], 'An environment proxy would resolve the host itself and bypass the pin.');
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_an_ipv6_literal_is_pinned_in_the_spelling_the_request_url_carries(): void
    {
        // The URI rewrites an IPv6 literal into its canonical form, and CURLOPT_CONNECT_TO matches on
        // the host as the request URL spells it — so the pin entry has to use that spelling too.
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->fetcher()->get('https://[2606:2800:0220:0001:0248:1893:25c8:1946]/page', 1024);

        $this->assertSame('[2606:2800:220:1:248:1893:25c8:1946]', $this->sent[0]['request']->getUri()->getHost());
        $this->assertSame(
            ['[2606:2800:220:1:248:1893:25c8:1946]:443:[2606:2800:220:1:248:1893:25c8:1946]:443'],
            $this->sent[0]['options']['curl'][CURLOPT_CONNECT_TO],
        );
    }

    public function test_a_redirect_to_a_private_address_is_refused(): void
    {
        // The first hop is impeccable and the second is where the attack lives; validating only the
        // pasted URL would follow this straight to localhost.
        $this->resolves('example.com', ['93.184.216.34']);
        $this->resolves('internal.example.com', ['127.0.0.1']);
        $this->respondsWith(new Response(302, ['Location' => 'https://internal.example.com/']));

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('non-global address [127.0.0.1]');

        $this->fetcher()->get('https://example.com/page', 1024);
    }

    public function test_a_relative_redirect_resolves_against_the_url_that_issued_it(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(301, ['Location' => '/moved']));
        $this->respondsWith(new Response(200, [], 'arrived'));

        $response = $this->fetcher()->get('https://example.com/deep/page', 1024);

        $this->assertSame('https://example.com/moved', (string) $this->sent[1]['request']->getUri());
        $this->assertSame('https://example.com/moved', $response->url);
        $this->assertSame('arrived', $response->body);
    }

    public function test_a_cross_host_redirect_is_resolved_and_revalidated_against_the_new_host(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);
        $this->resolves('cdn.example.net', ['203.0.113.5']); // TEST-NET-3: non-global
        $this->respondsWith(new Response(302, ['Location' => '//cdn.example.net/asset']));

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('[cdn.example.net] resolves to the non-global address');

        $this->fetcher()->get('https://example.com/page', 1024);
    }

    public function test_it_stops_after_the_redirect_limit(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);

        foreach (range(1, 10) as $i) {
            $this->respondsWith(new Response(302, ['Location' => "https://example.com/{$i}"]));
        }

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('More than 3 redirects');

        $this->fetcher()->get('https://example.com/page', 1024);
    }

    public function test_a_host_answering_with_both_public_and_private_addresses_is_refused(): void
    {
        // Which address arrives first is the answerer's choice, so one private address poisons the set.
        $this->resolves('example.com', ['93.184.216.34', '10.0.0.5']);
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('non-global address [10.0.0.5]');

        $this->fetcher()->get('https://example.com/page', 1024);
    }

    public function test_a_host_that_does_not_resolve_fails_rather_than_being_dialled(): void
    {
        $this->resolves('example.com', []);

        try {
            $this->fetcher()->get('https://example.com/page', 1024);
            $this->fail('Expected an OutboundException.');
        } catch (OutboundException $e) {
            $this->assertFalse($e->isBlocked(), 'An empty answer is transient, not a policy refusal.');
        }

        $this->assertSame([], $this->sent);
    }

    public function test_an_address_literal_is_judged_directly_without_a_lookup(): void
    {
        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('Refused address [169.254.169.254]');

        // The cloud metadata endpoint: no DNS involved, so a resolver-only guard would miss it.
        $this->fetcher()->get('http://169.254.169.254/latest/meta-data/', 1024);
    }

    public function test_a_public_address_literal_is_allowed(): void
    {
        $this->respondsWith(new Response(200, [], 'ok'));

        $response = $this->fetcher()->get('http://93.184.216.34/page', 1024);

        $this->assertSame('ok', $response->body);
        $this->assertSame(['93.184.216.34:80:93.184.216.34:80'], $this->sent[0]['options']['curl'][CURLOPT_CONNECT_TO]);
    }

    public function test_a_bracketed_ipv6_literal_is_pinned_with_brackets(): void
    {
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->fetcher()->get('https://[2606:2800:220:1:248:1893:25c8:1946]/page', 1024);

        $this->assertSame(
            ['[2606:2800:220:1:248:1893:25c8:1946]:443:[2606:2800:220:1:248:1893:25c8:1946]:443'],
            $this->sent[0]['options']['curl'][CURLOPT_CONNECT_TO],
        );
    }

    public function test_it_refuses_a_url_carrying_userinfo(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('userinfo');

        $this->fetcher()->get('https://user:secret@example.com/page', 1024);
    }

    public function test_it_refuses_a_scheme_that_is_not_http(): void
    {
        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('Refused scheme [file]');

        $this->fetcher()->get('file:///etc/passwd', 1024);
    }

    public function test_it_refuses_a_port_outside_http_and_https(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('Refused port [11211]');

        $this->fetcher()->get('http://example.com:11211/', 1024);
    }

    public function test_it_refuses_an_over_long_url(): void
    {
        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('length limit');

        $this->fetcher()->get('https://example.com/'.str_repeat('a', 4096), 1024);
    }

    public function test_a_url_the_uri_parser_refuses_is_blocked_before_it_is_resolved(): void
    {
        // `parse_url()` accepts this host and Guzzle's URI parser does not, so the refusal is a policy
        // answer that comes before the resolver sees the host.
        $this->resolves('ex%zz.example', ['93.184.216.34']);

        try {
            $this->fetcher()->get('https://ex%zz.example/page', 1024);
            $this->fail('Expected an OutboundException.');
        } catch (OutboundException $e) {
            $this->assertTrue($e->isBlocked());
            $this->assertStringContainsString('Malformed URL', $e->getMessage());
        }

        $this->assertSame([], $this->resolved, 'A malformed URL must not reach the resolver.');
        $this->assertSame([], $this->sent);
    }

    public function test_a_trailing_dot_host_is_canonicalised_so_the_pin_matches(): void
    {
        // "example.com." resolves the same but compares unequal, which would leave CURLOPT_CONNECT_TO
        // matching nothing and the request going wherever the system resolver pointed.
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->fetcher()->get('https://example.com./page', 1024);

        $this->assertSame('https://example.com/page', (string) $this->sent[0]['request']->getUri());
        $this->assertSame(['example.com:443:93.184.216.34:443'], $this->sent[0]['options']['curl'][CURLOPT_CONNECT_TO]);
    }

    public function test_an_internationalised_host_is_sent_as_punycode_so_the_pin_matches(): void
    {
        $this->resolves('xn--r8jz45g.xn--zckzah', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->fetcher()->get('https://例え.テスト/page', 1024);

        $this->assertSame('https://xn--r8jz45g.xn--zckzah/page', (string) $this->sent[0]['request']->getUri());
        $this->assertSame(['xn--r8jz45g.xn--zckzah:443:93.184.216.34:443'], $this->sent[0]['options']['curl'][CURLOPT_CONNECT_TO]);
    }

    public function test_an_over_long_location_is_not_followed(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(302, ['Location' => 'https://example.com/'.str_repeat('a', 4096)], 'body'));

        $response = $this->fetcher()->get('https://example.com/page', 1024);

        $this->assertCount(1, $this->sent);
        $this->assertSame(302, $response->status);
    }

    public function test_it_carries_no_cookies_credentials_or_referer(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->fetcher()->get('https://example.com/page', 1024);

        $request = $this->sent[0]['request'];
        $options = $this->sent[0]['options'];

        $this->assertFalse($request->hasHeader('Cookie'));
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertFalse($request->hasHeader('Referer'));
        $this->assertFalse($options['cookies']);
        $this->assertTrue($options['verify'], 'TLS verification is not something this fetcher may turn off.');
        $this->assertArrayNotHasKey('auth', $options);
        // ~/.netrc stays unread by libcurl's default; OutboundTransportTest pins that the option bag
        // cannot turn it on, so nothing here needs to turn it off.
        $this->assertSame([CURLOPT_CONNECT_TO], array_keys($options['curl']));
        $this->assertStringStartsWith('OpenPNE/4', $request->getHeaderLine('User-Agent'));
    }

    public function test_it_refuses_a_response_that_arrived_from_an_unvalidated_address(): void
    {
        // If `CURLOPT_CONNECT_TO` ever fails to match, the request still succeeds against whatever
        // DNS chose, and reading the peer address back is what catches it.
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'), connectedTo: '10.0.0.5');

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('Connected to [10.0.0.5]');

        $this->fetcher()->get('https://example.com/page', 1024);
    }

    public function test_it_refuses_a_response_when_the_transport_reports_no_peer_address(): void
    {
        // A transport that did not say where it connected is in the same evidential position as a
        // bad address, so accepting it would make the verification vanish exactly where it is needed.
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'), connectedTo: self::NO_PEER_REPORTED);

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('did not report which address');

        $this->fetcher()->get('https://example.com/page', 1024);
    }

    public function test_it_accepts_a_response_from_a_validated_address_written_differently(): void
    {
        $this->resolves('example.com', ['2606:2800:0220:0001:0248:1893:25c8:1946']);
        $this->respondsWith(new Response(200, [], 'ok'), connectedTo: '2606:2800:220:1:248:1893:25c8:1946');

        $this->assertSame('ok', $this->fetcher()->get('https://example.com/page', 1024)->body);
    }

    public function test_a_capped_body_keeps_the_real_status_and_content_type(): void
    {
        // The transfer aborts before Guzzle returns a response, but the response it had already built
        // rides along on the exception; synthesising a 200 would tell the caller a truncated 404 page
        // was the resource.
        $this->resolves('example.com', ['93.184.216.34']);
        $this->queueWriteAbort(new Response(404, ['Content-Type' => 'image/png'], 'PNGDATA-and-more'));

        $response = $this->fetcher()->get('https://example.com/missing.png', 6);

        $this->assertSame(404, $response->status);
        $this->assertSame('image/png', $response->mediaType());
        $this->assertSame('PNGDAT', $response->body);
        $this->assertTrue($response->truncated);
    }

    public function test_a_body_exactly_at_the_cap_is_not_reported_as_truncated(): void
    {
        // Length alone cannot tell a complete response that happens to be cap-sized from one that
        // was cut short, so the flag comes from the sink rather than from strlen().
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, ['Content-Type' => 'text/plain'], 'exact'));

        $response = $this->fetcher()->get('https://example.com/page', 5);

        $this->assertSame('exact', $response->body);
        $this->assertFalse($response->truncated);
    }

    public function test_a_failed_request_reports_the_url_without_its_query(): void
    {
        // The Guzzle message below is shaped like Guzzle 7's, which appended the request URI to the
        // cURL error text; Guzzle 8 redacts it, but the wording is upstream's to change, so the
        // underlying message is not repeated at all.
        $url = 'https://example.com/doc?token=s3cr3t-value';
        $this->resolves('example.com', ['93.184.216.34']);
        $this->failsWith(new ConnectException(
            "cURL error 6: Could not resolve host (see https://curl.se/libcurl/c/libcurl-errors.html) for {$url}",
            new Request('GET', $url),
        ), errno: 6);

        try {
            $this->fetcher()->get($url, 1024);
            $this->fail('Expected an OutboundException.');
        } catch (OutboundException $e) {
            $this->assertStringNotContainsString('s3cr3t-value', $e->getMessage());
            $this->assertStringNotContainsString('token=', $e->getMessage());
            $this->assertStringContainsString('https://example.com/doc', $e->getMessage());
            // Still diagnostic enough to tell a DNS failure from a TLS one.
            $this->assertStringContainsString('curl errno 6', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'A retained previous exception would print the full URL when logged.');
        }
    }

    public function test_a_transfer_that_fails_after_the_response_headers_is_still_a_failure(): void
    {
        // A response-aware failure (the body cut off by a reset) arrives as a `ResponseException`
        // like a capped transfer does, and only the sink's own cut is recovered; this one stays a
        // failure.
        $url = 'https://example.com/doc?token=s3cr3t-value';
        $this->resolves('example.com', ['93.184.216.34']);
        $this->failsWith(new ResponseTransferException(
            'cURL error 18: transfer closed with outstanding read data remaining',
            new Request('GET', $url),
            new Response(200, ['Content-Type' => 'text/html'], 'partial'),
        ), errno: 18);

        try {
            $this->fetcher()->get($url, 1024);
            $this->fail('Expected an OutboundException.');
        } catch (OutboundException $e) {
            $this->assertFalse($e->isBlocked(), 'A transport failure is transient, not a policy refusal.');
            $this->assertStringContainsString('ResponseTransferException, curl errno 18', $e->getMessage());
            $this->assertStringNotContainsString('s3cr3t-value', $e->getMessage());
        }
    }

    public function test_a_redirect_limit_failure_reports_the_url_without_its_query(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);

        foreach (range(1, 10) as $i) {
            $this->respondsWith(new Response(302, ['Location' => "https://example.com/{$i}?token=s3cr3t-value"]));
        }

        try {
            $this->fetcher()->get('https://example.com/start?token=s3cr3t-value', 1024);
            $this->fail('Expected an OutboundException.');
        } catch (OutboundException $e) {
            $this->assertStringNotContainsString('s3cr3t-value', $e->getMessage());
        }
    }

    public function test_it_gives_up_when_the_caller_deadline_has_passed(): void
    {
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('Ran out of time');

        // A job budget shared across page + oEmbed + image: once it is spent, no further request goes out.
        $this->fetcher()->get('https://example.com/page', 1024, microtime(true) - 1);
    }

    /**
     * On a host that burns the whole window before the check, the older already-past guard fires
     * and this proves nothing without failing.
     */
    public function test_it_gives_up_when_less_than_a_millisecond_of_the_deadline_is_left(): void
    {
        // Guzzle rejects a timeout in (0, 0.001) seconds as invalid rather than treating it as none,
        // and the fake transport has no such validation, so if the guard let this through the fetch
        // would succeed.
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'));

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('Ran out of time');

        $this->fetcher()->get('https://example.com/page', 1024, microtime(true) + 0.0009);
    }

    private function resolves(string $host, array $addresses): void
    {
        $this->dns[$host] = $addresses;
    }

    /**
     * @param  string|null  $connectedTo  The peer address the transport will report; null keeps the
     *                                    default (the address the pin named), and NO_PEER_REPORTED
     *                                    models a transport that says nothing.
     */
    private function respondsWith(Response $response, ?string $connectedTo = null): void
    {
        $this->queue[] = [$response, $connectedTo];
    }

    /**
     * Queue a response whose body overruns the sink, as libcurl aborting on a short write does.
     *
     * Guzzle's curl handler raises a ResponseException with the response it had already built
     * attached (CurlFactory::createRejection), which is what lets the real status survive.
     */
    private function queueWriteAbort(Response $response): void
    {
        $this->queue[] = [$response, null, true];
    }

    /**
     * @param  int|null  $errno  The curl errno; Guzzle hands it to on_stats as the handler error
     *                           data before rejecting, which is where the fetcher reads it.
     */
    private function failsWith(GuzzleException $failure, ?int $errno = null): void
    {
        $this->queue[] = [$failure, $errno];
    }

    private function fetcher(): SafeHttpFetcher
    {
        $handler = function (RequestInterface $request, array $options) {
            $this->sent[] = ['request' => $request, 'options' => $options];

            $queued = array_shift($this->queue) ?? [new Response(200, [], ''), null];

            if ($queued[0] instanceof GuzzleException) {
                if (isset($options['on_stats'])) {
                    $partial = $queued[0] instanceof ResponseException ? $queued[0]->getResponse() : null;
                    $options['on_stats'](new TransferStats($request, $partial, 0.0, $queued[1], []));
                }

                return Create::rejectionFor($queued[0]);
            }

            [$response, $connectedTo] = $queued;
            $abortOnCap = $queued[2] ?? false;
            $capped = false;

            if (isset($options['sink'])) {
                $options['sink']->write((string) $response->getBody());
                $capped = $abortOnCap && $options['sink']->wasCapped();
                $response = $response->withBody($options['sink']);
            }

            if (isset($options['on_stats'])) {
                // Stands in for what libcurl reports: the default models the pin working (the peer is
                // the pinned address), since defaulting to no address would send every success case
                // down the unverified path; an aborted write reports `CURLE_WRITE_ERROR` as libcurl
                // does.
                $stats = match ($connectedTo) {
                    null => ['primary_ip' => $this->pinnedAddress($options)],
                    self::NO_PEER_REPORTED => [],
                    default => ['primary_ip' => $connectedTo],
                };

                $options['on_stats'](new TransferStats($request, $response, 0.0, $capped ? 23 : 0, $stats));
            }

            if ($capped) {
                return Create::rejectionFor(new ResponseException('Unable to write to stream', $request, $response));
            }

            return Create::promiseFor($response);
        };

        return new SafeHttpFetcher(
            client: new Client(['handler' => HandlerStack::create($handler)]),
            resolver: new class(fn (string $host): array => $this->lookup($host)) implements HostResolver
            {
                public function __construct(private readonly \Closure $lookup) {}

                public function resolve(string $host): array
                {
                    return ($this->lookup)($host);
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

    /** @return list<string> */
    private function lookup(string $host): array
    {
        $this->resolved[] = $host;

        return $this->dns[$host] ?? [];
    }

    /** The address CURLOPT_CONNECT_TO named, read back out of the option the fetcher set. */
    private function pinnedAddress(array $options): string
    {
        // HOST:PORT:ADDRESS:PORT, where either host may be a bracketed IPv6 literal full of colons.
        $entry = $options['curl'][CURLOPT_CONNECT_TO][0] ?? '';

        if (preg_match('/^(?:\[[^\]]+\]|[^:]+):\d+:(\[[^\]]+\]|[^:]+):\d+$/', $entry, $matches) !== 1) {
            $this->fail("CURLOPT_CONNECT_TO entry [{$entry}] is not in HOST:PORT:ADDRESS:PORT form.");
        }

        return trim($matches[1], '[]');
    }
}
