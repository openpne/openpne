<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Notifications\PushSubscriptionController;
use App\Notifications\Push\WebPushNudge;
use App\Outbound\PushClientFactory;
use Tests\TestCase;

/**
 * Holds the arithmetic that bounds a push job.
 *
 * A job is what it bounds, so the ceiling is only there on a queue that runs one. On `sync`,
 * `deferred` and `background` the notification is sent in the process that queued it and no job
 * timeout exists to apply — the send blocks whatever was running, which for a push triggered by
 * someone else's post is that person's own request. Only the per-request timeout and the device cap
 * bound it there. That is why they are kept small enough to be a tolerable answer on their own.
 *
 * The transport sends a member's devices one after another — the library's flush() calls a PSR-18
 * sendRequest() per subscription, and that interface is synchronous by definition — so the option
 * named `timeout` bounds one request while the job runs for the product of it and the device cap.
 * Every one of the three numbers lives somewhere else (a config file, a controller constant, a
 * notification property) and each looks harmless on its own, which is why the relation between
 * them is asserted rather than left as a comment.
 *
 * What is on the other side of the line: a job that outlives retry_after is handed to a second
 * worker while the first is still sending, so every device that did answer gets pushed twice. A
 * member only needs to register endpoints that never respond — nothing stops them, since
 * PushEndpoint checks the shape of a URL and not what is behind it.
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
     * A site that deletes the timeout from its config gets the factory's, and that one has to hold
     * the same bound — it is the value that applies exactly when nobody is looking at this file.
     */
    public function test_the_transports_own_default_holds_the_same_bound(): void
    {
        $worst = PushSubscriptionController::MAX_DEVICES * PushClientFactory::FALLBACK_TIMEOUT;

        $this->assertLessThanOrEqual((new WebPushNudge(null, null, null))->timeout, $worst);
    }

    /**
     * Connections that do not hand a job out again on a timer this app can read.
     *
     * `sync`, `deferred` and `background` run the job in the process that queued it, so there is no
     * reservation to expire, and `failover` delegates to the connections it is given. `sqs` is the
     * one that carries a requirement: its reservation window is the AWS queue's visibility timeout,
     * which defaults to 30 seconds — below the ceiling asserted here, so a deployment on SQS has to
     * raise it past `WebPushNudge::$timeout`. Named rather than skipped by absence, so a driver
     * added later has to be looked at instead of quietly leaving the assertion behind.
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
