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
 * Diaries by the viewer's friends, newest first: the threshold is Friends, so a friend's Private
 * entries stay out and no friends at all yields an empty feed. Owners blocking the viewer are
 * excluded for the friend who has since blocked them.
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
