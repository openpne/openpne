<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Notifications\PushSubscriptionController;
use App\Notifications\Push\WebPushNudge;
use Tests\TestCase;

/**
 * Holds the arithmetic that bounds a push job.
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
        $worst = PushSubscriptionController::MAX_DEVICES * (int) config('webpush.client_options.timeout');

        $this->assertLessThanOrEqual((new WebPushNudge(null, null, null))->timeout, $worst);
    }

    public function test_the_job_gives_up_before_any_queue_hands_it_out_again(): void
    {
        $timeout = (new WebPushNudge(null, null, null))->timeout;

        foreach ((array) config('queue.connections') as $name => $connection) {
            if (! isset($connection['retry_after'])) {
                continue;
            }

            $this->assertLessThan(
                (int) $connection['retry_after'],
                $timeout,
                "The push job can outlive the {$name} queue's retry_after, which pushes a member's live devices twice.",
            );
        }
    }
}
