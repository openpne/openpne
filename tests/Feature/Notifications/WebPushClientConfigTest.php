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
 * Asserted on the client the channel would actually send on rather than on the config it was built from,
 * the package registering a binding for the same client. `allow_redirects` is asserted for completeness
 * only: Guzzle's PSR-18 sendRequest() sets it false per request whatever the client carries, so the teeth
 * here are `proxy` and the two timeouts.
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
     * The timeout here is deliberately not the factory's own fallback: identical values would let this
     * pass with the two swapped for each other.
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

    /**
     * Resolved through the boot the app did, never by registering the provider by hand, so this is what
     * watches that it is listed in bootstrap/providers.php.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(): array
    {
        $channel = $this->app->make(WebPushChannel::class);
        $webPush = (new ReflectionProperty(WebPushChannel::class, 'webPush'))->getValue($channel);
        $client = (new ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);

        $this->assertInstanceOf(Client::class, $client);

        return (new ReflectionProperty(Client::class, 'config'))->getValue($client);
    }
}
