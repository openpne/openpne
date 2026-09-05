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
 * The broadcast audience for a new diary, chunked by the fan-out job
 * (docs/internals/notifications.md, "Broadcast fan-out"). Whether a recipient is also a friend — the
 * friends-only opt-in bucket — is decided from friendIds() rather than re-queried per member.
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
