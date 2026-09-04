<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Substituted beneath the real WebPushChannel, so the subscription lookup, the payload and the report
 * handling (expiry deletion included) run on every call. The swap must use the package's contextual
 * binding; a global instance() for WebPush is not what the channel resolves.
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
