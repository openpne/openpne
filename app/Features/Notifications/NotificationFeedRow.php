<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use Carbon\CarbonInterface;

final readonly class NotificationFeedRow
{
    public function __construct(
        public string $id,
        public string $label,
        public ?CarbonInterface $createdAt,
        public bool $read,
    ) {}
}
