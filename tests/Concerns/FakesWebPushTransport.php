<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Substitutes the push transport underneath the channel, the way FakesOutboundTransport does for the
 * fetcher: the real WebPushChannel still runs, so the subscription lookup, the payload it builds and
 * the report handling (including expiry deletion) execute on every call. Faking the channel itself
 * would let all of that regress without a red test.
 *
 * The swap must use the same contextual binding the package registers — a global instance() for
 * Minishlink\WebPush\WebPush is not what the channel resolves.
 */
trait FakesWebPushTransport
{
    protected FakeWebPush $webPushTransport;

    /** Give the site a VAPID keypair, which is what switches the whole feature on. */
    protected function configureVapid(): void
    {
        config([
            'webpush.vapid.subject' => 'https://sns.example.com',
            'webpush.vapid.public_key' => 'BFakePublicKeyForTests_0123456789abcdefghijklmnopqrstuvwxyz-ABCDEFGHIJKLMNOPQ',
            'webpush.vapid.private_key' => 'FakePrivateKeyForTests_0123456789abcdefghijk',
        ]);
    }

    protected function fakeWebPushTransport(): FakeWebPush
    {
        $this->webPushTransport = new FakeWebPush;

        app()->when(WebPushChannel::class)
            ->needs(WebPush::class)
            ->give(fn (): WebPush => $this->webPushTransport);

        return $this->webPushTransport;
    }

    /**
     * The payloads delivered to $endpoint, decoded.
     *
     * @return list<array<string, mixed>>
     */
    protected function pushesTo(string $endpoint): array
    {
        return array_values(array_map(
            static fn (array $sent): array => (array) json_decode((string) $sent['payload'], true),
            array_filter(
                $this->webPushTransport->sent,
                static fn (array $sent): bool => $sent['endpoint'] === $endpoint,
            ),
        ));
    }
}
