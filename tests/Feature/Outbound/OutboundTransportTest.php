<?php

declare(strict_types=1);

namespace Tests\Feature\Outbound;

use App\Outbound\CurlClientFactory;
use App\Outbound\OutboundException;
use App\Outbound\SafeHttpFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The SSRF guard rests on libcurl honouring CURLOPT_CONNECT_TO, which the unit tests cannot show:
 * they drive a fake handler, where the curl options are inert data. These pin the transport itself,
 * because every way of losing it is silent — Guzzle falls back to the PHP stream handler when
 * ext-curl is missing, and there every CURLOPT_* is ignored while requests keep succeeding, aimed
 * wherever the system resolver points.
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
        $this->assertFalse(is_callable($inner) && $inner instanceof StreamHandler);
    }

    private function clientOf(SafeHttpFetcher $fetcher): Client
    {
        $client = (new ReflectionProperty(SafeHttpFetcher::class, 'client'))->getValue($fetcher);

        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }
}
