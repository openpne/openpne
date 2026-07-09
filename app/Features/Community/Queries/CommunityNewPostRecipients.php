<?php

namespace App\Features\Community\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The broadcast audience for a new community posting (OpenPNE 3 opCommunityTopicToolKit::getSendList):
 * the community's confirmed members (pending applicants live in community_join_requests, so
 * community_members is already the confirmed set), minus the author, minus banned members
 * (is_login_rejected, the catalog-wide receive gate), minus either-direction blocks against the author.
 * Shared by the topic and event new-post fan-outs — the audience is the community, not the item.
 */
class CommunityNewPostRecipients
{
    public function viewers(Community $community, Member $author): Builder
    {
        $query = Member::query()
            ->whereKeyNot($author->getKey())
            ->where('is_login_rejected', false)
            ->whereIn('id', DB::table('community_members')
                ->where('community_id', $community->getKey())
                ->select('member_id'));

        BlockLookup::excludeBlockedBetween($query, $author, 'members.id');

        return $query;
    }
}
