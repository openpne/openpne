<?php

declare(strict_types=1);

namespace Tests\Feature\Outbound;

use App\Outbound\CurlClientFactory;
use App\Outbound\HostResolver;
use App\Outbound\OutboundException;
use App\Outbound\PublicIpGuard;
use App\Outbound\PushClientFactory;
use App\Outbound\SafeHttpFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The SSRF guard rests on libcurl honouring CURLOPT_CONNECT_TO, which the unit tests cannot show:
 * they drive a fake handler, where the curl options are inert data. These pin the transport itself,
 * because every way of losing it is silent — Guzzle falls back to the PHP stream handler when
 * ext-curl is missing, and there every CURLOPT_* is ignored while requests keep succeeding, aimed
 * wherever the system resolver points.
 *
 * The option bags the two seams build are also put through Guzzle's real curl handler
 * configuration here. Its allow-list of raw curl options is what makes the pins final, and the
 * flip side is that an option it refuses is a request that never goes out — a fake handler would
 * carry it happily.
 */
class OutboundTransportTest extends TestCase
{
    public function test_ext_curl_is_present(): void
    {
        $this->assertTrue(
            extension_loaded('curl'),
            'composer.json requires ext-curl: without it Guzzle ignores the connection pin.',
        );
    }

    public function test_libcurl_supports_connect_to(): void
    {
        // CURLOPT_CONNECT_TO landed in libcurl 7.49.0.
        $this->assertGreaterThanOrEqual(
            0x073100,
            curl_version()['version_number'],
            'libcurl is too old for CURLOPT_CONNECT_TO, so the connection pin would not apply.',
        );
    }

    public function test_it_refuses_to_build_a_client_on_a_libcurl_that_cannot_pin(): void
    {
        // A self-hoster compiles their own PHP, where ext-curl can be present but linked against a
        // libcurl older than CURLOPT_CONNECT_TO — the option is then accepted and ignored. The
        // after-the-fact peer check does not catch that, because the system resolver normally hands
        // back the same addresses the guard validated; the gap opens only when someone controls the
        // answer, which is the case being defended against. So the factory refuses instead.
        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('libcurl 7.49.0 or newer');

        (new CurlClientFactory(minLibcurl: PHP_INT_MAX))->make();
    }

    public function test_it_builds_a_client_on_this_build(): void
    {
        $this->assertInstanceOf(Client::class, (new CurlClientFactory)->make());
    }

    public function test_the_fetcher_is_wired_to_the_curl_handler(): void
    {
        $client = $this->clientOf($this->app->make(SafeHttpFetcher::class));

        $handler = $client->getConfig('handler');
        $this->assertInstanceOf(HandlerStack::class, $handler);

        $inner = new ReflectionProperty(HandlerStack::class, 'handler');

        $this->assertInstanceOf(
            CurlHandler::class,
            $inner->getValue($handler),
            'The handler must be an explicit CurlHandler, not Guzzle default selection.',
        );
    }

    public function test_the_fetcher_is_not_wired_to_the_stream_handler(): void
    {
        $client = $this->clientOf($this->app->make(SafeHttpFetcher::class));
        $inner = (new ReflectionProperty(HandlerStack::class, 'handler'))->getValue($client->getConfig('handler'));

        $this->assertNotInstanceOf(CurlMultiHandler::class, $inner);
        $this->assertNotInstanceOf(StreamHandler::class, $inner);
    }

    public function test_the_fetchers_request_options_pass_the_curl_handlers_validation(): void
    {
        [$request, $options] = $this->optionsTheFetcherSends();

        // CurlFactory::create() is where the curl handler validates the bag — raw options against
        // its allow-list, the proxy option, the timeouts — and prepares the easy handle. No network.
        $factory = new CurlFactory(1);
        $easy = $factory->create($request, $options);
        $factory->release($easy);

        $this->assertSame(['example.com:443:93.184.216.34:443'], $easy->options['curl'][CURLOPT_CONNECT_TO]);
        $this->assertSame('', $easy->options['proxy']);
    }

    public function test_the_push_clients_request_options_pass_the_curl_handlers_validation(): void
    {
        $captured = null;
        $handler = function (RequestInterface $request, array $options) use (&$captured) {
            $captured = [$request, $options];

            return Create::promiseFor(new Response(201));
        };
        // The bag as the channel would hand it over, with the factory's pins and the sink middleware
        // applied on top, captured below the whole stack.
        $client = (new PushClientFactory)->make([
            ...config('webpush.client_options'),
            'handler' => HandlerStack::create($handler),
        ]);
        $client->sendRequest(new Request('POST', 'https://push.example.com/s'));

        [$request, $options] = $captured;
        $factory = new CurlFactory(1);
        $easy = $factory->create($request, $options);
        $factory->release($easy);

        // Only the proxy pin is asserted here: sendRequest() forces allow_redirects off itself, so
        // that one is held by WebPushClientConfigTest on the client config instead.
        $this->assertSame('', $easy->options['proxy']);
    }

    /**
     * What the seams' pins rely on Guzzle refusing from the `curl` sub-array.
     *
     * Guzzle 7 applied that array last, so a site could undo the proxy, redirect and sink pins in
     * CURLOPT_ terms; Guzzle 8 refuses those options there. The netrc entry is the same guarantee
     * from the other side: credentials from ~/.netrc stay off because libcurl's default ignores the
     * file and nothing in the option bag can turn it back on.
     */
    #[DataProvider('rawCurlOptionsTheHandlerRefuses')]
    public function test_the_curl_handler_refuses_the_raw_options_that_could_undo_a_pin(int $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CurlFactory(1))->create(new Request('GET', 'https://example.com/'), ['curl' => [$option => $value]]);
    }

    /** @return array<string, array{int, mixed}> */
    public static function rawCurlOptionsTheHandlerRefuses(): array
    {
        return [
            'proxy' => [CURLOPT_PROXY, 'http://proxy.example.com:3128'],
            'no-proxy list' => [CURLOPT_NOPROXY, '*'],
            'follow redirects' => [CURLOPT_FOLLOWLOCATION, true],
            'write callback (the sink)' => [CURLOPT_WRITEFUNCTION, static fn (): int => 0],
            'netrc' => [CURLOPT_NETRC, CURL_NETRC_OPTIONAL],
        ];
    }

    /**
     * The request and option bag SafeHttpFetcher hands its handler for a plain fetch.
     *
     * @return array{RequestInterface, array<string, mixed>}
     */
    private function optionsTheFetcherSends(): array
    {
        $captured = null;
        $handler = function (RequestInterface $request, array $options) use (&$captured) {
            $captured = [$request, $options];
            $response = new Response(200, [], 'ok');
            $options['on_stats'](new TransferStats($request, $response, 0.0, 0, ['primary_ip' => '93.184.216.34']));

            return Create::promiseFor($response);
        };
        $fetcher = new SafeHttpFetcher(
            client: new Client(['handler' => HandlerStack::create($handler)]),
            resolver: new class implements HostResolver
            {
                public function resolve(string $host): array
                {
                    return ['93.184.216.34'];
                }
            },
            guard: new PublicIpGuard,
            userAgent: 'OpenPNE/4 (+https://sns.example.com)',
            connectTimeout: 3,
            requestTimeout: 8,
            fetchTimeout: 10,
            maxRedirects: 3,
        );

        $fetcher->get('https://example.com/page', 1024);

        $this->assertNotNull($captured);

        return $captured;
    }

    private function clientOf(SafeHttpFetcher $fetcher): Client
    {
        $client = (new ReflectionProperty(SafeHttpFetcher::class, 'client'))->getValue($fetcher);

        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }
}
