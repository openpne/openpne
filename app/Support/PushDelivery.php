<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One global switch, not a per-kind set: which events reach the feed is the notification catalog's
 * decision, and push adds no eligibility of its own (docs/internals/notifications.md). Subscribing a
 * device is the consent, so this only pauses delivery and defaults to Enabled.
 */
enum PushDelivery: string
{
    case Enabled = 'enabled';

    case Disabled = 'disabled';
}
