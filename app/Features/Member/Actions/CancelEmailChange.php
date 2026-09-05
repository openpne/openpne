<?php

namespace App\Features\Member\Actions;

use App\Models\EmailChangeRequest;

/** Idempotent, and `members.email` is never touched: only the pending row is dropped. */
class CancelEmailChange
{
    public function __invoke(EmailChangeRequest $pending): void
    {
        EmailChangeRequest::whereKey($pending->getKey())->delete();
    }
}
