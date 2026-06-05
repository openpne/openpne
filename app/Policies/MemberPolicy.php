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
}
