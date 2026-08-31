<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Notifications\Push\WebPushNudge;
use App\Outbound\CappedStream;
use App\Outbound\PushClientFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * A push send reads the response's status and nothing else, and the endpoint it answers from is
 * member-supplied — so the body is bounded at the client, per request, and the status the channel
 * acts on still arrives through that bound.
 */
class WebPushResponseBodyBoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_request_carries_its_own_sink_that_refuses_bytes_past_the_cap(): void
    {
        $seen = [];
        $handler = function (RequestInterface $request, array $options) use (&$seen) {
            $seen[] = $options;

            return Create::promiseFor(new Response(201));
        };
        $client = (new PushClientFactory)->make(['handler' => HandlerStack::create($handler)]);

        $client->sendRequest(new Request('POST', 'https://push.example.com/a'));
        $client->sendRequest(new Request('POST', 'https://push.example.com/b'));

        [$first, $second] = array_map(fn (array $options) => $options[RequestOptions::SINK], $seen);
        $this->assertInstanceOf(CappedStream::class, $first);
        $this->assertNotSame($first, $second);
        // The short write is libcurl's signal to abort the transfer; nothing past the cap lands.
        $this->assertSame(PushClientFactory::MAX_RESPONSE_BYTES, $first->write(str_repeat('x', 1024 * 1024)));
        $this->assertTrue($first->wasCapped());
    }

    public function test_an_expired_subscription_is_still_recognised_through_the_bound(): void
    {
        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.vapid.subject' => 'https://sns.example.com',
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
        ]);
        $mock = new MockHandler([new Response(410, [], '{"reason":"gone"}')]);
        app()->when(WebPushChannel::class)
            ->needs(WebPush::class)
            ->give(fn (): WebPush => new WebPush(
                ['VAPID' => ['subject' => 'https://sns.example.com', 'publicKey' => $vapid['publicKey'], 'privateKey' => $vapid['privateKey']]],
                [],
                (new PushClientFactory)->make(['handler' => HandlerStack::create($mock)]),
                new HttpFactory,
                new HttpFactory,
            ));
        $member = Member::factory()->create();
        $member->updatePushSubscription(
            'https://push.example.com/gone-device',
            VAPID::createVapidKeys()['publicKey'],
            rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'aes128gcm',
        );

        $member->notify(new WebPushNudge('direct_message_received', null, null));

        // The channel expires a device on 404/410 — the status came through the bounded sink.
        $this->assertSame(0, $mock->count());
        $this->assertSame(0, $member->pushSubscriptions()->count());
    }
}
