<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Outbound\PushClientFactory;
use GuzzleHttp\Client;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Everything that keeps a validated push endpoint from becoming a request somewhere else is a
 * property of one client, so it is asserted on the client the channel would actually send on rather
 * than on the config it was built from.
 *
 * Going through the channel is the point. The package registers a binding for the same client and
 * builds it in a way that drops these options, so what is being pinned here is that this app's
 * provider is the one that won. A test of PushClientFactory alone would stay green while every push
 * went out on the package's client.
 *
 * `allow_redirects` is asserted for completeness, not because this client decides it: the only
 * method the library calls is Guzzle's PSR-18 sendRequest(), which sets allow_redirects false per
 * request whatever the client was built with. Remove it from the factory and this assertion fails
 * while behaviour does not — the teeth here are `proxy` and the two timeouts.
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

        $this->assertSame(config('webpush.client_options.connect_timeout'), $config['connect_timeout']);
        // Set explicitly, so the library's 30s default does not park a queue worker on a dead service.
        $this->assertSame(config('webpush.client_options.timeout'), $config['timeout']);
    }

    /**
     * The two options that decide where a request can land outlast a config that says otherwise.
     *
     * Both are read from a file an operator edits, and an empty or absent `proxy` is the difference
     * between ignoring HTTPS_PROXY and honouring it. So the factory applies them after whatever it
     * was handed, and a site that deletes them keeps them. Said the other way round — through the
     * `curl` sub-array, which Guzzle applies last of all — the same site can still open both; that
     * is why this says these options and not the config as a whole.
     *
     * The timeout here is deliberately not the factory's own fallback: identical values would let
     * this pass while the two were swapped for each other.
     */
    public function test_the_destination_options_outlast_config_that_states_otherwise(): void
    {
        $client = (new PushClientFactory)->make([
            'allow_redirects' => true,
            'proxy' => 'http://proxy.example.com:3128',
            'timeout' => 7,
        ]);

        $config = (new ReflectionProperty(Client::class, 'config'))->getValue($client);

        $this->assertFalse($config['allow_redirects']);
        $this->assertSame('', $config['proxy']);
        // What the config does own still lands.
        $this->assertSame(7, $config['timeout']);
    }

    /** @return array<string, mixed> */
    private function clientConfig(): array
    {
        $channel = $this->app->make(WebPushChannel::class);
        $webPush = (new ReflectionProperty(WebPushChannel::class, 'webPush'))->getValue($channel);
        $client = (new ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);

        $this->assertInstanceOf(Client::class, $client);

        return (new ReflectionProperty(Client::class, 'config'))->getValue($client);
    }
}
