<?php

namespace App\Policies;

use App\Models\Member;
use Illuminate\Auth\Access\Response;

class MemberPolicy extends BasePolicy
{
    /**
     * Gates only the one-way block; profile-field clearance and each page's guest rule stay with the
     * caller. Denies with 404 so a blocked viewer cannot tell the page exists.
     */
    public function access(?Member $viewer, Member $subject): Response
    {
        if ($viewer !== null && ! $viewer->is($subject) && $this->ownerBlocksViewer($subject, $viewer)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Ownership is the whole test: owner_member_id is written once at creation and never re-parented.
     * Denies with 404 so a member id naming someone else's AI account reads the same as one naming
     * nothing.
     */
    public function manageAiAccount(Member $viewer, Member $target): Response
    {
        return $target->isAiAccount() && (int) $target->owner_member_id === (int) $viewer->getKey()
            ? Response::allow()
            : Response::denyWithStatus(404);
    }
}
