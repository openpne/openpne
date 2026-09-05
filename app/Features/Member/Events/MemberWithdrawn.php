<?php

namespace App\Features\Member\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The payload is scalar and captured before the delete: a serialized Member would be a queued
 * reference to a row that no longer exists.
 */
class MemberWithdrawn implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $memberId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $locale,
        public readonly bool $wasAiAccount,
    ) {}
}
