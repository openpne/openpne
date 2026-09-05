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
 * The unit tests drive a fake handler where curl options are inert data, so the transport is pinned
 * here: losing `CURLOPT_CONNECT_TO` is silent, as Guzzle's stream-handler fallback ignores every
 * `CURLOPT_*` while requests keep succeeding. Both seams' option bags pass through Guzzle's curl
 * handler configuration, whose allow-list makes the pins final and a refused option a request that
 * never goes out.
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
        // A self-hoster's PHP can have ext-curl linked against a libcurl older than
        // `CURLOPT_CONNECT_TO`, which accepts and ignores the option, and the peer check does not
        // catch that.
        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('libcurl 7.49.0 or newer');

        (new CurlClientFactory(minLibcurl: PHP_INT_MAX))->make();
    }

    public function test_it_builds_a_client_on_this_build(): void
    {
        $this->assertInstanceOf(Client::class, (new CurlClientFactory)->make());
    }

    public function test_the_client_itself_refuses_a_proxy(): void
    {
        // The fetcher fixes `proxy` per request as well; this is the second layer, so a caller that
        // reaches the client another way still cannot be routed through the environment's proxy.
        $client = (new CurlClientFactory)->make();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('', $client->getConfig('proxy'), 'An absent proxy option is not neutral: Guzzle would take it from the environment.');
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

        // `CurlFactory::create()` is where the curl handler validates the bag (raw options against
        // its allow-list, the proxy option, the timeouts) and prepares the easy handle, with no network.
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
        // that one is held on the client config instead.
        $this->assertSame('', $easy->options['proxy']);
    }

    /**
     * Guzzle 8 refuses the raw options Guzzle 7 let a site undo the proxy, redirect and sink pins
     * with; the netrc entry is the same guarantee from the other side, since nothing in the option
     * bag can turn `~/.netrc` on.
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
