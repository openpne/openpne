<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The friend diary feed: diaries by the viewer's friends, newest
 * first. The threshold is Friends — a friend's Friends/Members/Open diaries all qualify, their
 * Private ones do not. No friends means an empty feed (whereIn on an empty set yields no rows).
 *
 * Blocking owners are excluded for the edge case of a friend who has since blocked the viewer.
 */
class ListFriendDiaries
{
    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($viewer)->paginate($perPage);
    }

    /**
     * First $limit diaries, unpaginated — for the home gadget list, which shows no pager and must
     * not read the host page's ?page=.
     *
     * @return Collection<int, Diary>
     */
    public function take(Member $viewer, int $limit): Collection
    {
        return $this->query($viewer)->limit($limit)->get();
    }

    /** @return Builder<Diary> */
    private function query(Member $viewer): Builder
    {
        $friendIds = $viewer->friendships()->pluck('members.id');

        $query = Diary::with('member.avatar.file')->withCount(['comments', 'images'])
            ->whereIn('member_id', $friendIds)
            ->where('visibility', '<=', Visibility::Friends->value);

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'diaries.member_id');

        return $query->orderByDesc('created_at');
    }
}
