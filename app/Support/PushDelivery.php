<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a member's subscribed devices are nudged when a feed row is written for them. One global
 * switch, not a per-kind set: which events reach the feed is already the notification catalog's
 * decision, and push adds no eligibility of its own (see docs/internals/notifications.md).
 * Subscribing a device is the consent, so this is the pause switch — Enabled by default.
 */
enum PushDelivery: string
{
    case Enabled = 'enabled';

    case Disabled = 'disabled';
}
