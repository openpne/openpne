<?php

namespace App\Policies;

use App\Models\Member;

abstract class BasePolicy
{
    /**
     * Whether $owner has blocked $viewer. The block override is one-way:
     * $owner's content is hidden from $viewer, but $viewer's content
     * is not symmetrically hidden from $owner.
     */
    protected function ownerBlocksViewer(Member $owner, Member $viewer): bool
    {
        return $owner->blocksMade()->whereKey($viewer->getKey())->exists();
    }
}
