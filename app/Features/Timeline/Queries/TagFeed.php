<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\HashtagParser;
use App\Features\Timeline\TimelineFeedScope;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * HomeFeed narrowed to the top-level posts carrying the tag: the audience is the home feed's,
 * unchanged, because a tag collects posts rather than widening who may read them. The term goes
 * through {@see HashtagParser::normalize} since the stored tag is normalized and the column is
 * byte-equal.
 */
class TagFeed
{
    /** @return LengthAwarePaginator<int, TimelinePost> */
    public function __invoke(Member $viewer, string $tag, int $perPage = 20): LengthAwarePaginator
    {
        $query = TimelinePost::query()
            ->whereNull('in_reply_to_id')
            ->whereExists(fn (QueryBuilder $sub) => $sub->select(DB::raw(1))
                ->from('timeline_post_tags')
                ->whereColumn('timeline_post_tags.timeline_post_id', 'timeline_posts.id')
                ->where('timeline_post_tags.tag', HashtagParser::normalize($tag)))
            ->with(['member.avatar.file', 'images.file', 'linkCard.image', 'mentions', 'tags'])
            ->withCount('replies');

        TimelineFeedScope::apply($query, $viewer);

        // created_at is the human-meaningful order; id DESC is the stable tiebreaker for same-second
        // posts (and migrated rows sharing a timestamp), matching the other timeline feeds.
        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }
}
