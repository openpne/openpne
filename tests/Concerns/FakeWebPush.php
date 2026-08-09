<?php

declare(strict_types=1);

namespace Tests\Concerns;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * The push transport FakesWebPushTransport installs: records what the channel queued and answers
 * with scripted reports. The parent constructor is skipped deliberately — it builds a real Guzzle
 * client, and nothing here needs one.
 */
class FakeWebPush extends WebPush
{
    /** Everything ever queued, across flushes. @var list<array{endpoint: string, payload: ?string, options: array<string, mixed>}> */
    public array $sent = [];

    /** The current, undrained batch — flush() reports on this alone, as the real one does. */
    private array $queued = [];

    /** @var array<string, int> endpoint => the status the push service answers with; absent means 201 */
    private array $statuses = [];

    private ?Throwable $failure = null;

    public function __construct() {}

    /** Answer $endpoint with $status — 410 is how a push service reports a dead subscription. */
    public function answers(string $endpoint, int $status): void
    {
        $this->statuses[$endpoint] = $status;
    }

    /** Make every flush throw, modelling a transport that never reached the service. */
    public function failsWith(Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
    {
        $entry = [
            'endpoint' => $subscription->getEndpoint(),
            'payload' => $payload,
            'options' => $options,
        ];

        $this->sent[] = $entry;
        $this->queued[] = $entry;
    }

    public function flush(?int $batchSize = null): \Generator
    {
        $batch = $this->queued;
        $this->queued = [];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        foreach ($batch as $entry) {
            $status = $this->statuses[$entry['endpoint']] ?? 201;

            yield new MessageSentReport(
                new Request('POST', $entry['endpoint']),
                new Response($status),
                $status >= 200 && $status < 300,
            );
        }
    }
}
