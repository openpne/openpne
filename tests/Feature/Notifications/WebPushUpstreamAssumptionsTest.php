<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

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
 * Two neighbouring assumptions are already covered elsewhere and are not repeated here: that the
 * channel sends through `flush()` (FakeWebPush overrides exactly that, so every other push test
 * fails if it stops being called), and that `queueNotification()` and `MessageSentReport` keep the
 * shapes that fake is written against (PHP refuses to declare the class otherwise).
 */
class WebPushUpstreamAssumptionsTest extends TestCase
{
    /**
     * The seam App\Providers\WebPushServiceProvider overrides is a protected method on the package's
     * provider. Renamed upstream, our override becomes a method nobody calls, and the client goes
     * back to the package's — which drops the option bag on this Laravel version, taking the proxy
     * and timeout guarantees with it.
     */
    public function test_the_client_seam_this_app_overrides_still_exists(): void
    {
        $this->assertTrue(
            method_exists(WebPushServiceProvider::class, 'webPushClient'),
            'The channel package no longer has webPushClient(), so App\Providers\WebPushServiceProvider overrides nothing and push is sent on the package\'s own client. Find where it builds the client now and override that instead — docs/internals/outbound-http.md, "The push endpoint seam".',
        );

        $seam = new ReflectionMethod(WebPushServiceProvider::class, 'webPushClient');

        $this->assertSame(ClientInterface::class, (string) $seam->getReturnType());
        $this->assertSame(1, $seam->getNumberOfParameters());
    }

    /**
     * The transport must not be able to reach an HTTP client this app did not build.
     *
     * `flushPooled()` sends on a separate async client the library discovers for itself, with no
     * options at all: redirects followed, the environment's proxy honoured, no timeouts. Nothing
     * installs an HTTPlug adapter today, so that discovery fails and the method throws — the seam's
     * guarantees hold because the alternative path does not exist rather than because it is safe.
     * A transitive dependency adding an adapter is all it would take.
     */
    public function test_no_async_client_is_available_to_send_push_unguarded(): void
    {
        $channel = $this->app->make(WebPushChannel::class);
        $webPush = (new ReflectionProperty(WebPushChannel::class, 'webPush'))->getValue($channel);

        $this->assertNull(
            (new ReflectionProperty(WebPush::class, 'asyncClient'))->getValue($webPush),
            'An HTTPlug async client is now installed, so WebPush::flushPooled() would send on a client with no proxy pin, no redirect refusal and no timeouts. Either keep the adapter out, or build that client in App\Outbound too.',
        );
    }

    /**
     * The transport sends one device at a time, which is the whole reason WebPushNudge needs a
     * ceiling of its own: the per-request timeout multiplies rather than bounds.
     *
     * Asserted by consuming one report and counting: sequentially, only the first request has been
     * made by then; the concurrent implementation this replaced had issued all of them before it
     * yielded anything. If this fails, the timeouts in config/webpush.php were sized for a cost that
     * no longer applies and WebPushTimeoutBudgetTest is asserting the wrong shape.
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

        // And the other two are made by draining it — so the count above is sequencing, not a queue
        // that silently dropped them.
        iterator_to_array($reports);

        $this->assertSame(3, $recorder->requests);
    }
}
