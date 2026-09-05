<?php

namespace App\Features\Group\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
