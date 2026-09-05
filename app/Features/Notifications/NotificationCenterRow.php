<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\File;

final class NotificationCenterRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly NotificationCenterCategory $category,
        public readonly bool $read,
        public readonly ?int $actorId,
        public readonly ?string $actorName,
        public readonly ?File $actorAvatar,
        /**
         * Answered by the request itself, never by the row's read state: reading the panel must not
         * retract the decision.
         */
        public readonly bool $awaitingDecision = false,
    ) {}
}
