<?php

namespace App\Policies;

use App\Features\Community\CommunityMembership;
use App\Models\Community;
use App\Models\Member;

class CommunityPolicy
{
    /** Phase A: any authenticated member may view any community. */
    public function view(Member $viewer, Community $community): bool
    {
        return true;
    }

    /** Edit community settings (name, description, category, policy): admin or sub-admin. */
    public function update(Member $actor, Community $community): bool
    {
        return CommunityMembership::canManage($community, $actor);
    }

    public function delete(Member $actor, Community $community): bool
    {
        return CommunityMembership::isAdmin($community, $actor);
    }

    /** Approve/decline pending members (and later member moderation): admin only. */
    public function manageMembers(Member $actor, Community $community): bool
    {
        return CommunityMembership::isAdmin($community, $actor);
    }
}
