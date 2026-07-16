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

    /**
     * Friend-scoped variant of apply(): the viewer's own posts at every visibility, plus a friend's
     * posts up to friends-only. Unlike apply() it drops the all-members tier, so a non-friend's
     * members-only post never appears — the feed is limited to the viewer and the people they friended.
     *
     * @param  Builder<TimelinePost>  $query
     */
    public static function applyFriendsOnly(Builder $query, Member $viewer): void
    {
        $viewerId = $viewer->getKey();

        $query->where(function (Builder $audience) use ($viewerId) {
            $audience
                // Your own posts, at every visibility (including Private).
                ->where('timeline_posts.member_id', $viewerId)
                // A friend's posts, up to friends-only (their Private stays hidden).
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
     * SNS-wide members-only variant: posts every member may see (visibility <= Members), with no
     * viewer-specific tiers. Unlike apply() it adds neither the viewer's own Private posts nor a
     * friend's friends-only posts — the feed is exactly what any member sees — matching OpenPNE 3's
     * getAllMemberActivityList. Authors who block the viewer are then dropped.
     *
     * @param  Builder<TimelinePost>  $query
     */
    public static function applyMembersOnly(Builder $query, Member $viewer): void
    {
        $query->where('timeline_posts.visibility', '<=', Visibility::Members->value);

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'timeline_posts.member_id');
    }
}
