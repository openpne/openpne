<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Providers\WebPushServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\HttpAsyncClientDiscovery;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushServiceProvider as PackageWebPushServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

/**
 * Assumptions about the packages under the push seam, asserted so a bump to
 * laravel-notification-channels/webpush, minishlink/web-push or guzzlehttp/guzzle is what goes red
 * (docs/internals/outbound-http.md, The push endpoint seam). Two neighbouring ones are not repeated: that
 * the channel sends through `flush()`, and that `queueNotification()` and `MessageSentReport` keep the
 * shapes FakeWebPush is written against.
 */
class WebPushUpstreamAssumptionsTest extends TestCase
{
    /**
     * Narrowing it to private is the quiet break: the parent would then call its own copy and leave the
     * override a method that merely exists.
     */
    public function test_the_client_seam_this_app_overrides_is_still_overridable(): void
    {
        $this->assertTrue(
            method_exists(PackageWebPushServiceProvider::class, 'webPushClient'),
            'The channel package no longer has webPushClient(), so App\Providers\WebPushServiceProvider overrides nothing. Find where it builds the client now and override that instead — docs/internals/outbound-http.md, "The push endpoint seam".',
        );

        $this->assertTrue(
            (new ReflectionMethod(PackageWebPushServiceProvider::class, 'webPushClient'))->isProtected(),
            'The channel package made webPushClient() private, so App\Providers\WebPushServiceProvider no longer overrides it and the parent builds the client itself.',
        );
    }

    /**
     * Asserted by emptying the config before the provider boots — the binding closes over a config array
     * read at boot time — so only PushClientFactory can still have these set.
     */
    public function test_the_channel_sends_on_the_client_this_app_built(): void
    {
        config(['webpush.client_options' => []]);
        $this->app->register(new WebPushServiceProvider($this->app), true);

        $config = $this->clientConfig();

        $this->assertFalse(
            $config['allow_redirects'] ?? null,
            'Push is going out on a client App\Outbound\PushClientFactory did not build, so nothing is applying the seam\'s no-redirect and no-proxy pins. The package reaches its own builder again — see docs/internals/outbound-http.md, "The push endpoint seam".',
        );

        $this->assertSame(
            '',
            $config['proxy'] ?? null,
            'The push client no longer refuses a proxy, so the environment decides where a validated endpoint is dialled.',
        );
    }

    /**
     * Asserted against discovery rather than against the instance the library keeps
     * (docs/internals/outbound-http.md, The push endpoint seam).
     */
    public function test_no_async_client_is_available_to_send_push_unguarded(): void
    {
        $discovered = null;

        try {
            $discovered = HttpAsyncClientDiscovery::find();
        } catch (Throwable) {
            // Not finding one is the state being asserted; how discovery says so is its own business.
        }

        $this->assertNull(
            $discovered,
            'An HTTPlug async client is now installed, so WebPush::flushPooled() would send on a client with no proxy pin, no redirect refusal and no timeouts. Either keep the adapter out of the lock, or build that client in App\Outbound too.',
        );
    }

    /**
     * Asserted by consuming one report and counting: one request has been made by then and the remaining
     * two only on draining, so an implementation issuing them together would satisfy neither half.
     */
    public function test_the_transport_still_sends_one_device_at_a_time(): void
    {
        $recorder = new class implements ClientInterface
        {
            public int $requests = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests++;

                return new Response(201);
            }
        };

        $vapid = VAPID::createVapidKeys();
        $webPush = new WebPush(
            ['VAPID' => [
                'subject' => 'https://sns.example.com',
                'publicKey' => $vapid['publicKey'],
                'privateKey' => $vapid['privateKey'],
            ]],
            [],
            $recorder,
            new HttpFactory,
            new HttpFactory,
        );

        foreach (range(1, 3) as $device) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => "https://push.example.com/device/{$device}",
            ]));
        }

        $reports = $webPush->flush();
        $reports->current();

        $this->assertSame(
            1,
            $recorder->requests,
            'The transport no longer sends one device at a time. Re-read the timeouts in config/webpush.php: they were lowered because a job costs timeout x MAX_DEVICES, and that is no longer what it costs.',
        );

        iterator_to_array($reports);

        $this->assertSame(3, $recorder->requests);
    }

    /**
     * Each hop is checked by name first: a property renamed upstream is exactly the routine bump this file
     * exists to explain, and a bare ReflectionException explains nothing.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(): array
    {
        $this->assertTrue(property_exists(WebPushChannel::class, 'webPush'), 'WebPushChannel no longer keeps its transport in $webPush.');
        $this->assertTrue(property_exists(WebPush::class, 'client'), 'WebPush no longer keeps its HTTP client in $client.');

        $channel = $this->app->make(WebPushChannel::class);
        $webPush = (new ReflectionProperty(WebPushChannel::class, 'webPush'))->getValue($channel);
        $client = (new ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);

        $this->assertInstanceOf(Client::class, $client);

        return (new ReflectionProperty(Client::class, 'config'))->getValue($client);
    }
}
