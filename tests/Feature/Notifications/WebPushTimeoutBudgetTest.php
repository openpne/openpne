<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Notifications\PushSubscriptionController;
use App\Notifications\Push\WebPushNudge;
use App\Outbound\PushClientFactory;
use GuzzleHttp\Client;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Each of the three numbers lives somewhere else — a config file, a controller constant, a
 * notification property — and each looks harmless alone, so the relation between them is asserted
 * rather than left as a comment (docs/internals/outbound-http.md, "The push endpoint seam").
 */
class WebPushTimeoutBudgetTest extends TestCase
{
    public function test_a_full_batch_of_unresponsive_devices_finishes_inside_the_jobs_timeout(): void
    {
        $timeout = config('webpush.client_options.timeout');

        // Fail here rather than read a missing key as nought, which would satisfy every bound below.
        $this->assertIsInt($timeout);

        $worst = PushSubscriptionController::MAX_DEVICES * $timeout;

        $this->assertLessThanOrEqual((new WebPushNudge(null, null, null))->timeout, $worst);
    }

    /**
     * Read off a client the factory actually built rather than off the constant it is written with, since
     * a site that deletes the timeout from its config gets the factory's.
     */
    public function test_the_transports_own_default_holds_the_same_bound(): void
    {
        $client = (new PushClientFactory)->make([]);
        $config = (new ReflectionProperty(Client::class, 'config'))->getValue($client);

        $worst = PushSubscriptionController::MAX_DEVICES * $config['timeout'];

        $this->assertLessThanOrEqual((new WebPushNudge(null, null, null))->timeout, $worst);
    }

    /**
     * `sync`, `deferred` and `background` run the job in the process that queued it, so there is no
     * reservation to expire, `failover` delegates, and `sqs` carries a requirement of its own
     * (docs/internals/outbound-http.md, "The push endpoint seam"). Named rather than skipped by
     * absence, so a
     * driver added later has to be looked at.
     *
     * @var list<string>
     */
    private const NO_READABLE_RESERVATION_WINDOW = ['sync', 'deferred', 'background', 'failover', 'sqs'];

    public function test_the_job_gives_up_before_any_queue_hands_it_out_again(): void
    {
        $timeout = (new WebPushNudge(null, null, null))->timeout;

        foreach ((array) config('queue.connections') as $name => $connection) {
            if (in_array($name, self::NO_READABLE_RESERVATION_WINDOW, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                'retry_after',
                $connection,
                "The {$name} queue states no retry_after, so nothing here bounds when it hands a running push job to a second worker. Either it reserves on a window set outside this app — say so in NO_READABLE_RESERVATION_WINDOW, with what an operator has to set — or it needs one.",
            );

            $this->assertLessThan(
                (int) $connection['retry_after'],
                $timeout,
                "The push job can outlive the {$name} queue's retry_after, which pushes a member's live devices twice.",
            );
        }
    }
}
