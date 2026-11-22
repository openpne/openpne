<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;
use Throwable;

/**
 * Logs the endpoint's host, never the endpoint, which is the device's push capability, and never a
 * response body (docs/internals/notifications.md, "Web push"). It runs inside the channel's loop that
 * sends each device only once the previous report is handled, so nothing may escape it.
 */
final class LogWebPushFailure
{
    private const MAX_LENGTH = 256;

    public function handle(NotificationFailed $event): void
    {
        try {
            $report = $event->report;
            $response = $report->getResponse();
            $context = array_filter([
                'member_id' => $event->subscription->subscribable_id,
                'host' => $report->getRequest()->getUri()->getHost(),
                'status' => $response?->getStatusCode(),
                'reason' => $response === null ? self::clean($report->getReason()) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            if ($report->isSubscriptionExpired()) {
                Log::info('Web push endpoint is gone; its subscription was deleted.', $context);

                return;
            }

            Log::warning('Web push delivery failed.', $context);
        } catch (Throwable $e) {
            try {
                // report() writes to the same log store whose failure is the likely cause, so it can throw too.
                report($e);
            } catch (Throwable) {
            }
        }
    }

    /** Every URL keeps only its origin, because the reason Guzzle gives for a failed transfer quotes the endpoint's path. */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('~(https?://[^/\s?#]+)\S*~i', '$1', $text);

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text)), 0, self::MAX_LENGTH);
    }
}
