<?php

declare(strict_types=1);

namespace App\Notifications\Push;

use Illuminate\Support\Arr;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The package's toArray() is a bare array_filter, which drops a body of "0" — a message a member
 * did write — along with the unset fields.
 */
final class WebPushPayload extends WebPushMessage
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Arr::except(
            array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [] && $value !== false),
            ['options'],
        );
    }
}
