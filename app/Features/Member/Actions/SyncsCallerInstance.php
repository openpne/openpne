<?php

namespace App\Features\Member\Actions;

use App\Models\Member;

/**
 * All two-factor state transitions verify AND mutate the row locked FOR UPDATE — handing Fortify's
 * actions the caller's (possibly stale) authenticated instance would let their internal state
 * guards silently no-op against a snapshot the lock just invalidated. After commit, the committed
 * attributes are mirrored back onto the caller's instance so same-request readers do not keep
 * reporting the pre-transaction state.
 */
trait SyncsCallerInstance
{
    private function syncCaller(Member $viewer, Member $fresh): void
    {
        $viewer->setRawAttributes($fresh->getAttributes(), true);
    }
}
