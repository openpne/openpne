<?php

namespace App\Features\Member\Actions;

use App\Models\EmailChangeRequest;

/**
 * Voids a pending email change (the cancel link in the old-address notice). Idempotent: it only drops
 * the pending row, so a double-submit or a row already gone (confirmed, superseded, or purged by a
 * password change) is a harmless no-op. members.email is never touched here.
 */
class CancelEmailChange
{
    public function __invoke(EmailChangeRequest $pending): void
    {
        EmailChangeRequest::whereKey($pending->getKey())->delete();
    }
}
