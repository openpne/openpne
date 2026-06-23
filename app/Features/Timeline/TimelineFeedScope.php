<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Constrains the cross-member home feed to what a viewer may see, matching OpenPNE 3's
 * opActivityQueryBuilder home feed (includeSelf + includeFriends + includeSns): the viewer's own
 * posts at every visibility, anyone's web-public or all-members posts, and a friend's friends-only
 * posts. Authors who block the viewer are then dropped, so a post whose permalink would 404 for the
 * viewer never surfaces here (the multi-owner counterpart of TimelineAccess / TimelineVisibilityScope).
 */
final class TimelineFeedScope
{
    /** @param  Builder<TimelinePost>  $query */
    public static function apply(Builder $query, Member $viewer): void
    {
        $viewerId = $viewer->getKey();

        $query->where(function (Builder $audience) use ($viewerId) {
            $audience
                // Your own posts, at every visibility (including Private).
                ->where('timeline_posts.member_id', $viewerId)
                // Anyone's web-public or all-members posts.
                ->orWhere('timeline_posts.visibility', '<=', Visibility::Members->value)
                // A friend's friends-only posts.
                ->orWhere(function (Builder $friends) use ($viewerId) {
                    $friends
                        ->where('timeline_posts.visibility', Visibility::Friends->value)
                        ->whereExists(function ($sub) use ($viewerId) {
                            $sub->select(DB::raw(1))
                                ->from('friendships')
                                ->where('friendships.member_id', $viewerId)
                                ->whereColumn('friendships.friend_id', 'timeline_posts.member_id');
                        });
                });
        });

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'timeline_posts.member_id');
    }
}
