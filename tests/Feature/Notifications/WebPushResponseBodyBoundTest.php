<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Notifications\Push\WebPushNudge;
use App\Outbound\CappedStream;
use App\Outbound\PushClientFactory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
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
 * acts on still arrives when the bound cuts a transfer short.
 *
 * The cut is played by a handler shaped like Guzzle's curl handler on a write error: it writes what
 * it can into the request's sink and rejects with a ResponseException that carries the response
 * (CurlFactory::createRejection). A MockHandler cannot stand in — it copies the body into the sink
 * and hands the response on whole, so nothing about the bound would be exercised.
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
        // A `sink` in the option bag does not displace it: the bound is pinned like proxy and redirects.
        $client = (new PushClientFactory)->make([
            'handler' => HandlerStack::create($handler),
            'sink' => Utils::streamFor(fopen('php://temp', 'r+')),
        ]);

        $client->sendRequest(new Request('POST', 'https://push.example.com/a'));
        $client->sendRequest(new Request('POST', 'https://push.example.com/b'));

        [$first, $second] = array_map(fn (array $options) => $options[RequestOptions::SINK], $seen);
        $this->assertInstanceOf(CappedStream::class, $first);
        $this->assertNotSame($first, $second);
        // The short write is libcurl's signal to abort the transfer; nothing past the cap lands.
        $this->assertSame(PushClientFactory::MAX_RESPONSE_BYTES, $first->write(str_repeat('x', 1024 * 1024)));
        $this->assertTrue($first->wasCapped());
    }

    public function test_a_transfer_the_bound_cuts_short_still_answers_with_its_status(): void
    {
        $client = (new PushClientFactory)->make(['handler' => HandlerStack::create($this->cutShortAt(410))]);

        $response = $client->sendRequest(new Request('POST', 'https://push.example.com/s'));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(PushClientFactory::MAX_RESPONSE_BYTES, $response->getBody()->getSize());
    }

    public function test_any_other_failure_stays_a_failure(): void
    {
        $handler = fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(new ConnectException('refused', $request));
        $client = (new PushClientFactory)->make(['handler' => HandlerStack::create($handler)]);

        $this->expectException(ConnectException::class);
        $client->sendRequest(new Request('POST', 'https://push.example.com/s'));
    }

    public function test_a_transfer_cut_short_for_any_other_reason_stays_a_failure(): void
    {
        // A partial transfer (CURLE_PARTIAL_FILE, a reset) also arrives as a ResponseException — the
        // response-transfer kind — carrying the response built so far. Only the sink's own cut is
        // recovered; this one wrote nothing.
        $handler = fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
            new ResponseTransferException('cURL error 18: transfer closed with outstanding read data remaining', $request, new Response(410)),
        );
        $client = (new PushClientFactory)->make(['handler' => HandlerStack::create($handler)]);

        $this->expectException(ResponseException::class);
        $client->sendRequest(new Request('POST', 'https://push.example.com/s'));
    }

    public function test_an_expired_device_answering_at_length_is_still_retired(): void
    {
        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.vapid.subject' => 'https://sns.example.com',
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
        ]);
        $sent = 0;
        $handler = $this->cutShortAt(410);
        $counting = function (RequestInterface $request, array $options) use ($handler, &$sent): PromiseInterface {
            $sent++;

            return $handler($request, $options);
        };
        app()->when(WebPushChannel::class)
            ->needs(WebPush::class)
            ->give(fn (): WebPush => new WebPush(
                ['VAPID' => ['subject' => 'https://sns.example.com', 'publicKey' => $vapid['publicKey'], 'privateKey' => $vapid['privateKey']]],
                [],
                (new PushClientFactory)->make(['handler' => HandlerStack::create($counting)]),
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

        // The channel retires a device on 404/410: the status survived the cut transfer.
        $this->assertSame(1, $sent);
        $this->assertSame(0, $member->pushSubscriptions()->count());
    }

    /**
     * Guzzle's curl handler on a write error: as much of a body far past the cap as the sink takes,
     * then a ResponseException carrying the response it had built.
     */
    private function cutShortAt(int $status): callable
    {
        return function (RequestInterface $request, array $options) use ($status): PromiseInterface {
            $options[RequestOptions::SINK]->write(str_repeat('x', 1024 * 1024));

            return Create::rejectionFor(new ResponseException('Unable to write to stream', $request, new Response($status)));
        };
    }
}
