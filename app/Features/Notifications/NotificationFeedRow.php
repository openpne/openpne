<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use Carbon\CarbonInterface;

/**
 * One rendered feed row for the Classic list: the resolved sentence, when it happened, and whether
 * it is still unread. `id` keys the open POST — where the row leads is resolved by that request,
 * not here, so listing a page costs no per-row access check.
 */
final readonly class NotificationFeedRow
{
    public function __construct(
        public string $id,
        public string $label,
        public ?CarbonInterface $createdAt,
        public bool $read,
    ) {}
}
