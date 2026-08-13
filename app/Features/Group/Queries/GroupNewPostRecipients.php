<?php

namespace App\Features\Group\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The broadcast audience for a new group posting:
 * the group's confirmed members (pending applicants live in group_join_requests, so
 * group_members is already the confirmed set), minus the author, minus banned members
 * (is_login_rejected, the catalog-wide receive gate), minus either-direction blocks against the author.
 * Shared by the topic and event new-post fan-outs — the audience is the group, not the item.
 */
class GroupNewPostRecipients
{
    public function viewers(Group $group, Member $author): Builder
    {
        $query = Member::query()
            ->whereKeyNot($author->getKey())
            ->where('is_login_rejected', false)
            ->whereIn('id', DB::table('group_members')
                ->where('group_id', $group->getKey())
                ->select('member_id'));

        BlockLookup::excludeBlockedBetween($query, $author, 'members.id');

        return $query;
    }
}
