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
 * Things the push seam takes for granted about the packages under it.
 *
 * None of these are this app's code, and none of them are announced when they change — the upgrade
 * that broke each of them the last time was a routine dependency bump whose release notes said
 * something else entirely. They are asserted here so the bump itself goes red, with a message saying
 * what to go and re-read, rather than the behaviour quietly becoming something else.
 *
 * Read this file when bumping laravel-notification-channels/webpush, minishlink/web-push, or
 * guzzlehttp/guzzle. The rest of the seam is docs/internals/outbound-http.md.
 *
 * Two neighbouring assumptions are covered elsewhere and not repeated: that the channel sends
 * through `flush()` (FakeWebPush overrides exactly that, so the push tests stop exercising anything
 * if it is not called), and that `queueNotification()` and `MessageSentReport` keep the shapes that
 * fake is written against (a changed signature on the override is a declaration-time error, and a
 * changed report constructor an ArgumentCountError from inside it).
 */
class WebPushUpstreamAssumptionsTest extends TestCase
{
    /**
     * The seam App\Providers\WebPushServiceProvider overrides has to stay a method it can override.
     *
     * Renaming it is the obvious break; narrowing it to private is the quiet one, since the parent
     * would then call its own copy and leave ours as a method that merely exists. Neither shows up
     * as anything but push going out on a client this app did not build.
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
     * And the client the channel sends on has to be the one this app built.
     *
     * Asserted by taking the config away first: with no options to pass on, the package's own
     * builder produces a client with neither of these set, and only PushClientFactory — which
     * applies them after whatever it was handed — still has them. So this stays green only while
     * that factory is what runs, however the package happens to reach it.
     *
     * The config is emptied before the provider boots because the binding closes over a config
     * array read at boot time; setting it afterwards changes nothing.
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

        $this->assertSame('', $config['proxy'] ?? null);
    }

    /**
     * The transport must not be able to reach an HTTP client this app did not build.
     *
     * `flushPooled()` sends on a separate async client discovered from whatever is installed, with
     * no options at all: redirects followed, the environment's proxy honoured, no timeouts. It
     * throws today only because nothing implements the interface it looks for — the seam's
     * guarantees hold because the alternative path does not exist, not because it is safe. A
     * transitive dependency pulling in an adapter is all it would take, and `php-http/discovery` is
     * an allowed plugin, so one can arrive without anyone naming it.
     *
     * Asserted against discovery rather than against the instance the library keeps: where it
     * stores the result, and whether it looks eagerly at all, is its own business.
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
     * The transport sends one device at a time, which is the whole reason WebPushNudge needs a
     * ceiling of its own: the per-request timeout multiplies rather than bounds.
     *
     * Asserted by consuming one report and counting — one request has been made by then, and the
     * remaining two only on draining. An implementation that issued them together would satisfy
     * neither half. If this fails, the timeouts in config/webpush.php were sized for a cost that no
     * longer applies and WebPushTimeoutBudgetTest is asserting the wrong shape.
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
     * The Guzzle client the channel resolved, reached the way WebPushClientConfigTest reaches it.
     *
     * Each hop is checked by name first: a property renamed upstream is exactly the routine bump
     * this file exists to explain, and a bare ReflectionException explains nothing.
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
