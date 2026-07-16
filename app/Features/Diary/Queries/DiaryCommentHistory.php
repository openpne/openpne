<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The OpenPNE 3 "diary comment history" box: other members' diaries the viewer commented on, ordered
 * by their latest comment. OpenPNE 3 kept a dedicated diary_comment_update subscription table, updated
 * by PluginDiaryComment::postSave only when the commenter is not the diary owner
 * (member_id !== Diary->member_id). That table was not ported, so this derives the same set from
 * diary_comments: candidates are non-own diaries the viewer commented on, and last_comment_time is the
 * MAX over non-owner comments only (an owner's own follow-up comment does not bump the box, matching
 * OpenPNE 3's owner exclusion).
 *
 * Divergence from OpenPNE 3: the subscription table never rewound on comment deletion. This derived
 * form recomputes from the surviving rows — a diary drops out once the viewer has no remaining comment
 * on it, and deleting whichever non-owner comment was latest rewinds the box's time to the next one.
 */
class DiaryCommentHistory
{
    /** @return Collection<int, Diary> */
    public function __invoke(Member $viewer, int $limit = 5): Collection
    {
        $viewerId = $viewer->getKey();

        $query = Diary::query()
            ->with('member')
            ->withCount('comments')
            ->addSelect(['last_comment_time' => DiaryComment::query()
                ->selectRaw('MAX(diary_comments.created_at)')
                ->whereColumn('diary_comments.diary_id', 'diaries.id')
                // A withdrawn commenter's member_id is NULL (nullOnDelete); on a surviving diary that
                // is necessarily a non-owner comment (owners cascade-delete their diaries), and a bare
                // != would drop it under SQL three-valued logic, rewinding the box's time.
                ->where(fn (Builder $q) => $q
                    ->whereNull('diary_comments.member_id')
                    ->orWhereColumn('diary_comments.member_id', '!=', 'diaries.member_id'))])
            // Candidate: the viewer commented on a diary that is not their own — so their comment is
            // necessarily a non-owner comment, matching OpenPNE 3's member_id !== owner condition.
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

        return $query->orderByDesc('last_comment_time')->orderByDesc('diaries.id')->limit($limit)->get();
    }
}
