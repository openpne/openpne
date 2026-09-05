<?php

namespace App\Features\Group\Queries;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Scoped to Admin, not canManage: the approval page's Gate (manageMembers) is Admin-only, so a
 * SubAdmin's notice would link to a page they get 403 on. Keep the two in lockstep.
 */
class PendingJoinRequestCounts
{
    /** @return Collection<int, Group> each with applicants_count loaded */
    public function __invoke(Member $viewer): Collection
    {
        return Group::query()
            ->whereHas('members', fn ($q) => $q
                ->where('member_id', $viewer->getKey())
                ->where('role', GroupRole::Admin->value))
            ->has('applicants')
            ->withCount('applicants')
            ->orderBy('id')
            ->get();
    }
}
