<?php

declare(strict_types=1);

namespace Tests\Unit\Outbound;

use App\Outbound\HostResolver;
use App\Outbound\OutboundException;
use App\Outbound\PublicIpGuard;
use App\Outbound\SafeHttpFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class SafeHttpFetcherTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<mixed>}> */
    private array $sent = [];

    /** @var list<Response> */
    private array $queue = [];

    /** @var array<string, list<string>> */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $this->queue = [];
        $this->dns = [];
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

        $curl = $this->sent[0]['options']['curl'];

        $this->assertSame(['example.com:443:93.184.216.34:443'], $curl[CURLOPT_CONNECT_TO]);
        $this->assertSame('', $curl[CURLOPT_PROXY], 'An environment proxy would resolve the host itself and bypass the pin.');
        $this->assertSame('*', $curl[CURLOPT_NOPROXY]);
        $this->assertFalse($this->sent[0]['options']['allow_redirects']);
    }

    public function test_a_redirect_to_a_private_address_is_refused(): void
    {
        // The first hop is impeccable; the second is where the attack lives. Validating only the
        // URL the member pasted would follow this straight to localhost.
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
        $this->assertSame(CURL_NETRC_IGNORED, $options['curl'][CURLOPT_NETRC]);
        $this->assertStringStartsWith('OpenPNE/4', $request->getHeaderLine('User-Agent'));
    }

    public function test_it_refuses_a_response_that_arrived_from_an_unvalidated_address(): void
    {
        // Belt and braces for the pin itself: if CURLOPT_CONNECT_TO ever fails to match, the request
        // still succeeds — against whatever DNS chose. Reading the peer address back catches that.
        $this->resolves('example.com', ['93.184.216.34']);
        $this->respondsWith(new Response(200, [], 'ok'), connectedTo: '10.0.0.5');

        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('Connected to [10.0.0.5]');

        $this->fetcher()->get('https://example.com/page', 1024);
    }

    public function test_it_accepts_a_response_from_a_validated_address_written_differently(): void
    {
        $this->resolves('example.com', ['2606:2800:0220:0001:0248:1893:25c8:1946']);
        $this->respondsWith(new Response(200, [], 'ok'), connectedTo: '2606:2800:220:1:248:1893:25c8:1946');

        $this->assertSame('ok', $this->fetcher()->get('https://example.com/page', 1024)->body);
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

    private function resolves(string $host, array $addresses): void
    {
        $this->dns[$host] = $addresses;
    }

    private function respondsWith(Response $response, ?string $connectedTo = null): void
    {
        $this->queue[] = [$response, $connectedTo];
    }

    private function fetcher(): SafeHttpFetcher
    {
        $handler = function (RequestInterface $request, array $options) {
            $this->sent[] = ['request' => $request, 'options' => $options];

            [$response, $connectedTo] = array_shift($this->queue) ?? [new Response(200, [], ''), null];

            if (isset($options['sink'])) {
                $options['sink']->write((string) $response->getBody());
                $response = $response->withBody($options['sink']);
            }

            if (isset($options['on_stats'])) {
                $options['on_stats'](new TransferStats(
                    $request,
                    $response,
                    0.0,
                    null,
                    $connectedTo === null ? [] : ['primary_ip' => $connectedTo],
                ));
            }

            return Create::promiseFor($response);
        };

        return new SafeHttpFetcher(
            client: new Client(['handler' => HandlerStack::create($handler)]),
            resolver: new class($this->dns) implements HostResolver
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
}
