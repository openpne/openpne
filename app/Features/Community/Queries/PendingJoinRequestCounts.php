<?php

namespace App\Features\Community\Queries;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Communities the viewer administers that have members awaiting approval, with the pending count —
 * for the dashboard's "join requests" notice, one row per community (the approval page is per
 * community). Scoped to Admin, not canManage: the approval page's Gate (manageMembers) is Admin-only,
 * so a SubAdmin's notice would link to a page they get 403 on. Keep the two in lockstep.
 */
class PendingJoinRequestCounts
{
    /** @return Collection<int, Community> each with applicants_count loaded */
    public function __invoke(Member $viewer): Collection
    {
        return Community::query()
            ->whereHas('members', fn ($q) => $q
                ->where('member_id', $viewer->getKey())
                ->where('role', CommunityRole::Admin->value))
            ->has('applicants')
            ->withCount('applicants')
            ->orderBy('id')
            ->get();
    }
}
