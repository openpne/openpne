<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The broadcast audience for a new diary (OpenPNE 3 PluginDiary::postInsert): the members who can see
 * it, chunked by the fan-out job. Audience by visibility — Open/Members → every active member,
 * Friends → the author's friends, Private → nobody. The author is excluded, banned members
 * (is_login_rejected, the receive gate used across the catalog) are excluded, and either-direction
 * blocks against the author are excluded. Whether each recipient is also a friend (for the
 * friends-only opt-in bucket) is decided from friendIds(), not re-queried per member.
 */
class DiaryPostedRecipients
{
    /** The member query of the audience, or null when the diary has no broadcast audience (Private). */
    public function viewers(Diary $diary): ?Builder
    {
        $author = $diary->member;
        if ($author === null || $diary->visibility === Visibility::Private) {
            return null;
        }

        $query = Member::query()
            ->whereKeyNot($author->getKey())
            ->where('is_login_rejected', false);

        BlockLookup::excludeBlockedBetween($query, $author, 'members.id');

        if ($diary->visibility === Visibility::Friends) {
            $query->whereIn('id', $this->friendIdsSubquery($author));
        }

        return $query;
    }

    /** The author's friend member ids, for the friends-only bucket check inside the fan-out. */
    public function friendIds(Member $author): Collection
    {
        return DB::table('friendships')->where('member_id', $author->getKey())->pluck('friend_id');
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function friendIdsSubquery(Member $author)
    {
        return DB::table('friendships')->where('member_id', $author->getKey())->select('friend_id');
    }
}
