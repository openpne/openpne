<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use GuzzleHttp\Client;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The push transport is a Guzzle client this app does not build, reached only through the package's
 * config. Everything that keeps a validated endpoint from becoming a request somewhere else lives in
 * that one array, so it is asserted where it lands — on the client the channel would actually use —
 * rather than by reading the config back.
 */
class WebPushClientConfigTest extends TestCase
{
    public function test_the_channels_client_follows_no_redirect_and_no_proxy(): void
    {
        $config = $this->clientConfig();

        // A 30x is the one move that turns a validated https endpoint into a request elsewhere.
        $this->assertFalse($config['allow_redirects']);
        // Set (to nothing), so Guzzle does not resolve HTTPS_PROXY/NO_PROXY from the environment.
        $this->assertSame('', $config['proxy']);
    }

    public function test_the_channels_client_is_bounded_in_time(): void
    {
        $config = $this->clientConfig();

        $this->assertSame(5, $config['connect_timeout']);
        // Set explicitly, so the package's 30s default does not park a queue worker on a dead service.
        $this->assertSame(10, $config['timeout']);
    }

    /** @return array<string, mixed> */
    private function clientConfig(): array
    {
        $channel = $this->app->make(WebPushChannel::class);
        $webPush = (new ReflectionProperty(WebPushChannel::class, 'webPush'))->getValue($channel);
        $client = (new ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);

        return (new ReflectionProperty(Client::class, 'config'))->getValue($client);
    }
}
