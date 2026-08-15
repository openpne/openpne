<?php

namespace App\Policies;

use App\Models\Member;
use Illuminate\Auth\Access\Response;

class MemberPolicy extends BasePolicy
{
    /**
     * Whether $viewer may reach $subject's member-scoped pages at all. This gates ONLY the
     * one-way block (the subject has blocked the viewer) — not profile-field visibility
     * (ShowProfile/clearance) nor the guest web-public gate (ProfileController). Denies with
     * 404 so a blocked viewer cannot tell the page exists, matching the profile/diary-show
     * responses. A guest (null viewer) cannot be blocked, so guests are allowed through here
     * and gated by each page's own guest rules.
     */
    public function access(?Member $viewer, Member $subject): Response
    {
        if ($viewer !== null && ! $viewer->is($subject) && $this->ownerBlocksViewer($subject, $viewer)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Whether $viewer may administer $target as one of their AI accounts — read its page, empty it
     * out of groups, delete it. Ownership is the whole test: `owner_member_id` is written once at
     * creation and never re-parented, so it is the account's permanent answer to "whose is this".
     *
     * Denies with 404 rather than 403, like access() above: a member id that names someone else's
     * AI account must read the same as one that names nothing.
     */
    public function manageAiAccount(Member $viewer, Member $target): Response
    {
        return $target->isAiAccount() && (int) $target->owner_member_id === (int) $viewer->getKey()
            ? Response::allow()
            : Response::denyWithStatus(404);
    }
}
