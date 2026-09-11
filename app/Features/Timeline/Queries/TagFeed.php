<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\HashtagParser;
use App\Features\Timeline\TimelineFeedScope;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Stream\StreamCursor;
use App\Support\Stream\StreamPage;
use App\Support\Stream\StreamQuery;
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
    /** @return StreamPage<TimelinePost> */
    public function __invoke(Member $viewer, string $tag, ?StreamCursor $before = null, int $perPage = RowsPage::DEFAULT): StreamPage
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

        return StreamQuery::older($query, $before, $perPage);
    }
}
