<?php

namespace App\Features\Member\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member was withdrawn (permanently deleted). The payload is scalar, captured before the delete: the
 * withdrawal mails address the now-gone member and the admin, and a serialized Member would be a
 * queued reference to a row that no longer exists. Dispatched after the delete commits.
 */
class MemberWithdrawn implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $memberId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $locale,
    ) {}
}
