<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The OpenPNE 3 "diary comment history" box, derived from `diary_comments` rather than from a
 * subscription table (docs/internals/diary.md, "The comment-history box").
 */
class DiaryCommentHistory
{
    /** @return Collection<int, Diary> */
    public function __invoke(Member $viewer, int $limit = 5): Collection
    {
        return $this->query($viewer)->limit($limit)->get();
    }

    /**
     * One builder feeds this page and the home box, so the two can never disagree.
     *
     * @return LengthAwarePaginator<int, Diary>
     */
    public function paginate(Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($viewer)->paginate($perPage)->withQueryString();
    }

    /** @return Builder<Diary> */
    private function query(Member $viewer): Builder
    {
        $viewerId = $viewer->getKey();

        $query = Diary::query()
            ->with('member')
            ->withCount('comments')
            ->addSelect(['last_comment_time' => DiaryComment::query()
                ->selectRaw('MAX(diary_comments.created_at)')
                ->whereColumn('diary_comments.diary_id', 'diaries.id')
                // A bare `!=` would drop a withdrawn commenter's NULL member_id under SQL's
                // three-valued logic (docs/internals/diary.md, "The comment-history box").
                ->where(fn (Builder $q) => $q
                    ->whereNull('diary_comments.member_id')
                    ->orWhereColumn('diary_comments.member_id', '!=', 'diaries.member_id'))])
            ->where('diaries.member_id', '!=', $viewerId)
            ->whereHas('comments', fn (Builder $q) => $q->where('member_id', $viewerId))
            ->where(function (Builder $audience) use ($viewerId) {
                $audience
                    ->where('diaries.visibility', '<=', Visibility::Members->value)
                    ->orWhere(function (Builder $friends) use ($viewerId) {
                        $friends
                            ->where('diaries.visibility', Visibility::Friends->value)
                            ->whereExists(function ($sub) use ($viewerId) {
                                $sub->select(DB::raw(1))
                                    ->from('friendships')
                                    ->where('friendships.member_id', $viewerId)
                                    ->whereColumn('friendships.friend_id', 'diaries.member_id');
                            });
                    });
            });

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'diaries.member_id');

        return $query->orderByDesc('last_comment_time')->orderByDesc('diaries.id');
    }
}
