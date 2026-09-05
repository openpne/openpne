<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The multi-owner counterpart of {@see TimelineAccess}: an author who blocks the viewer is dropped,
 * so a post whose permalink would 404 for them never surfaces in a feed. Every method here is an
 * SNS-wide feed, the posts table holding one audience again (docs/internals/timeline.md, "The
 * community timeline was replaced by group talk").
 */
final class TimelineFeedScope
{
    /** @param  Builder<TimelinePost>  $query */
    public static function apply(Builder $query, Member $viewer): void
    {
        $viewerId = $viewer->getKey();

        $query->where(function (Builder $audience) use ($viewerId) {
            $audience
                ->where('timeline_posts.member_id', $viewerId)
                ->orWhere('timeline_posts.visibility', '<=', Visibility::Members->value);

            // This branch IS the friend lens, so it goes with the unit — read-time clearance is
            // untouched, and a friend opening such a post still reads it (TimelineAccess).
            if (Feature::Friend->enabled()) {
                $audience->orWhere(function (Builder $friends) use ($viewerId) {
                    $friends
                        ->where('timeline_posts.visibility', Visibility::Friends->value)
                        ->whereExists(function ($sub) use ($viewerId) {
                            $sub->select(DB::raw(1))
                                ->from('friendships')
                                ->where('friendships.member_id', $viewerId)
                                ->whereColumn('friendships.friend_id', 'timeline_posts.member_id');
                        });
                });
            }
        });

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'timeline_posts.member_id');
    }

    /**
     * The viewer's own posts at every visibility plus a friend's up to friends-only; unlike apply()
     * it drops the all-members tier. No `friend` unit check here: its only callers are the two
     * friend-scoped gadgets, which the unit hides whole.
     *
     * @param  Builder<TimelinePost>  $query
     */
    public static function applyFriendsOnly(Builder $query, Member $viewer): void
    {
        $viewerId = $viewer->getKey();

        $query->where(function (Builder $audience) use ($viewerId) {
            $audience
                ->where('timeline_posts.member_id', $viewerId)
                ->orWhere(function (Builder $friends) use ($viewerId) {
                    $friends
                        ->where('timeline_posts.visibility', '<=', Visibility::Friends->value)
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

    /**
     * Posts every member may see, with no viewer-specific tiers — neither the viewer's own Private
     * posts nor a friend's friends-only ones (OpenPNE 3 getAllMemberActivityList). Authors who block
     * the viewer are then dropped.
     *
     * @param  Builder<TimelinePost>  $query
     */
    public static function applyMembersOnly(Builder $query, Member $viewer): void
    {
        $query->where('timeline_posts.visibility', '<=', Visibility::Members->value);

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'timeline_posts.member_id');
    }
}
