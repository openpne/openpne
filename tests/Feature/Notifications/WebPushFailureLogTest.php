<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Notifications\Push\WebPushNudge;
use App\Providers\WebPushServiceProvider;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\VAPID;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Sent through this app's own provider and client factory, so the reports are the real transport's and the
 * listener is reached by discovery rather than called by hand.
 */
class WebPushFailureLogTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $message): void {
            $this->logged[] = $message;
        });
    }

    public function test_a_refusal_is_a_warning_with_its_status_and_host_and_none_of_its_answer(): void
    {
        $member = $this->pushThrough(
            fn (): PromiseInterface => Create::promiseFor(new Response(429, [], 'internal-service-secret')),
            ['https://push.example.com/device/capability-token'],
        );

        [$line] = $this->logged('warning');
        $this->assertSame(['member_id', 'host', 'status'], array_keys($line->context));
        $this->assertEquals($member->getKey(), $line->context['member_id']);
        $this->assertSame('push.example.com', $line->context['host']);
        $this->assertSame(429, $line->context['status']);
    }

    /** A real Guzzle failure against a closed loopback port, so the reason is the one the library writes. */
    public function test_a_transport_failure_is_a_warning_whose_reason_quotes_no_endpoint_path(): void
    {
        $this->pushThrough(null, ['https://127.0.0.1:1/device/capability-token?k=v']);

        [$line] = $this->logged('warning');
        $this->assertArrayNotHasKey('status', $line->context);
        $this->assertStringContainsString('https://127.0.0.1:1', $line->context['reason']);
        $this->assertStringNotContainsString('capability-token', $line->context['reason']);
    }

    /** A reason Guzzle did not write, since the library already escapes the control characters in its own. */
    public function test_a_transport_reason_is_logged_as_one_bounded_line(): void
    {
        $this->pushThrough(
            fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(new ConnectException("refused\r\nforged line ".str_repeat('x', 1000), $request)),
            ['https://push.example.com/device/a'],
        );

        [$line] = $this->logged('warning');
        $this->assertStringStartsWith('refused forged line x', $line->context['reason']);
        $this->assertSame(256, mb_strlen($line->context['reason']));
    }

    public function test_a_transport_reason_quoting_the_endpoint_without_a_scheme_keeps_none_of_its_path(): void
    {
        $this->pushThrough(
            fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(new ConnectException('refused for push.example.com/device/capability-token?k=v', $request)),
            ['https://push.example.com/device/capability-token?k=v'],
        );

        [$line] = $this->logged('warning');
        $this->assertSame('refused for push.example.com', $line->context['reason']);
    }

    public function test_an_expired_endpoint_is_logged_below_warning(): void
    {
        $member = $this->pushThrough(fn (): PromiseInterface => Create::promiseFor(new Response(410)), ['https://push.example.com/device/gone']);

        $this->assertSame([], $this->logged('warning'));
        [$line] = $this->logged('info');
        $this->assertSame(410, $line->context['status']);
        $this->assertSame(0, $member->pushSubscriptions()->count());
    }

    public function test_a_delivered_push_logs_nothing(): void
    {
        $this->pushThrough(fn (): PromiseInterface => Create::promiseFor(new Response(201)), ['https://push.example.com/device/ok']);

        $this->assertSame([], array_merge($this->logged('warning'), $this->logged('info')));
    }

    /** The real exception handler, not a fake: reporting writes to the same failing log store. */
    public function test_a_failing_log_store_does_not_stop_the_remaining_devices_being_pushed(): void
    {
        Log::shouldReceive('warning')->twice()->andThrow(new RuntimeException('log store unavailable'));
        Log::shouldReceive('error')->twice()->andThrow(new RuntimeException('log store unavailable'));
        $sent = 0;

        $this->pushThrough(function () use (&$sent): PromiseInterface {
            $sent++;

            return Create::promiseFor(new Response(503));
        }, ['https://push.example.com/device/a', 'https://push.example.com/device/b']);

        $this->assertSame(2, $sent);
    }

    /**
     * @param  ?callable  $handler  null sends on Guzzle's own handler
     * @param  list<string>  $endpoints
     */
    private function pushThrough(?callable $handler, array $endpoints): Member
    {
        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.vapid.subject' => 'https://sns.example.com',
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
        ]);

        if ($handler !== null) {
            config(['webpush.client_options' => ['handler' => HandlerStack::create($handler)]]);
        }

        $this->app->register(new WebPushServiceProvider($this->app), true);
        $member = Member::factory()->create();

        foreach ($endpoints as $endpoint) {
            $member->updatePushSubscription(
                $endpoint,
                VAPID::createVapidKeys()['publicKey'],
                rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
                'aes128gcm',
            );
        }

        $member->notify(new WebPushNudge(['kind' => 'direct_message_received'], null));

        return $member;
    }

    /** @return list<MessageLogged> */
    private function logged(string $level): array
    {
        return array_values(array_filter(
            $this->logged,
            static fn (MessageLogged $message): bool => $message->level === $level && str_starts_with($message->message, 'Web push'),
        ));
    }
}
