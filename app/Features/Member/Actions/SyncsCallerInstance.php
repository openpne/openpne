<?php

namespace App\Features\Member\Actions;

use App\Models\Member;

/**
 * The committed attributes are mirrored back onto the caller's instance, so same-request readers do
 * not keep reporting the pre-transaction state.
 */
trait SyncsCallerInstance
{
    private function syncCaller(Member $viewer, Member $fresh): void
    {
        $viewer->setRawAttributes($fresh->getAttributes(), true);
    }
}
