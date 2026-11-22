<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;
use Psr\Http\Message\UriInterface;
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
            $endpoint = $report->getRequest()->getUri();
            $response = $report->getResponse();
            $context = array_filter([
                'member_id' => $event->subscription->subscribable_id,
                'host' => $endpoint->getHost(),
                'status' => $response?->getStatusCode(),
                'reason' => $response === null ? self::clean($report->getReason(), $endpoint) : null,
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

    /** The endpoint's path and query are cut wherever they appear and every URL keeps only its origin, so the capability stays out whatever wording upstream gives the reason (docs/internals/outbound-http.md, "Key invariants"). */
    private static function clean(string $text, UriInterface $endpoint): string
    {
        $text = str_replace(array_values(array_filter([
            strlen($endpoint->getPath()) > 1 ? $endpoint->getPath() : '',
            $endpoint->getQuery() !== '' ? '?'.$endpoint->getQuery() : '',
        ])), '', $text);
        $text = (string) preg_replace('~(https?://[^/\s?#]+)\S*~i', '$1', $text);

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text)), 0, self::MAX_LENGTH);
    }
}
