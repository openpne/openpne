<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Notifications\Push\WebPushNudge;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * FakeWebPush stands in for the transport everywhere else and never encrypts, so nothing else proves a key
 * that passed StorePushSubscriptionRequest encrypts end to end. A Guzzle MockHandler answers the send, so
 * real encryption runs with no network.
 */
class WebPushEncryptionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_validated_key_encrypts_through_the_real_transport(): void
    {
        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.vapid.subject' => 'https://sns.example.com',
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
        ]);

        $mock = new MockHandler([new Response(201)]);
        app()->when(WebPushChannel::class)
            ->needs(WebPush::class)
            ->give(fn (): WebPush => new WebPush(
                ['VAPID' => [
                    'subject' => 'https://sns.example.com',
                    'publicKey' => $vapid['publicKey'],
                    'privateKey' => $vapid['privateKey'],
                ]],
                [],
                new Client(['handler' => HandlerStack::create($mock)]),
                new HttpFactory,
                new HttpFactory,
            ));

        $member = Member::factory()->create();
        $member->updatePushSubscription(
            'https://push.example.com/real-device',
            VAPID::createVapidKeys()['publicKey'],
            rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'aes128gcm',
        );

        $member->notify(new WebPushNudge('direct_message_received', null, null));

        // A key that only threw inside Encryption would leave the scripted response untouched, so
        // consuming it is the assertion.
        $this->assertNotNull($mock->getLastRequest());
        $this->assertSame(0, $mock->count());
    }
}
