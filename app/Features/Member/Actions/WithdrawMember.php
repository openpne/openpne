<?php

namespace App\Features\Member\Actions;

use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Community\CommunityRole;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Withdraw (permanently delete) a member. Admin-initiated: the panel guard authorizes, so there is
 * no per-actor check here — only the primary-member guard.
 *
 * Most of the member's rows are removed by the `members` FK cascade (friendships, friend_requests,
 * member_blocks, community_members, community_join_requests, community_event_members,
 * member_profiles, member_preferences) and the avatar File is purged by MemberObserver::deleting().
 * SET-NULL relations are deliberately retained with a null author — the member's comments on others'
 * content, authored topics/events, and sent/received messages stay so the other parties' views keep
 * rendering (a withdrawn-member placeholder fills the null).
 *
 * Two things the cascade cannot do, handled explicitly here:
 *  - Image File *bytes* of cascade-deleted content (the member's own diaries + their comments, and
 *    timeline posts) — the cascade drops the *_image link rows but never the File bytes. We route
 *    each through its own delete action's purge so the bytes go too.
 *  - Sole-admin communities — flattened roles mean no implicit successor; hand over or delete.
 */
class WithdrawMember
{
    public function __construct(
        private readonly DeleteDiary $deleteDiary,
        private readonly DeleteTimelinePost $deleteTimelinePost,
        private readonly DeleteCommunity $deleteCommunity,
    ) {}

    public function __invoke(Member $member): void
    {
        // Defensive: the primary member (id 1) is never withdrawable. The admin UI also hides the
        // action for id 1 (MemberResource::canDelete), so reaching here is a programming error.
        if ((int) $member->getKey() === 1) {
            throw new RuntimeException('The primary member cannot be withdrawn.');
        }

        DB::transaction(function () use ($member): void {
            $this->handOverOrDeleteSoleAdminCommunities($member);

            // Own diaries: purge each (drops the diary + its comments and all their image bytes).
            foreach ($member->diaries()->get() as $diary) {
                $this->deleteDiary->purge($diary);
            }

            // Own top-level timeline posts: purge each (the image byte lives only on top-level posts;
            // replies carry none and cascade with the parent). The member's replies to others' posts
            // carry no image and are removed by the members cascade below.
            $topLevelPosts = TimelinePost::query()
                ->where('member_id', $member->getKey())
                ->whereNull('in_reply_to_id')
                ->get();

            foreach ($topLevelPosts as $post) {
                ($this->deleteTimelinePost)($post);
            }

            $member->delete();
        });
    }

    /**
     * For every community the member administers, keep the community governable after withdrawal:
     * if another admin remains, do nothing; else promote the longest-tenured remaining member
     * (OpenPNE 3's oldest-becomes-admin), or delete the community when no members remain.
     */
    private function handOverOrDeleteSoleAdminCommunities(Member $member): void
    {
        $adminMemberships = CommunityMember::query()
            ->where('member_id', $member->getKey())
            ->where('role', CommunityRole::Admin->value)
            ->get();

        foreach ($adminMemberships as $membership) {
            $communityId = $membership->community_id;

            $hasOtherAdmin = CommunityMember::query()
                ->where('community_id', $communityId)
                ->where('role', CommunityRole::Admin->value)
                ->where('member_id', '!=', $member->getKey())
                ->exists();

            if ($hasOtherAdmin) {
                continue;
            }

            $successor = CommunityMember::query()
                ->where('community_id', $communityId)
                ->where('member_id', '!=', $member->getKey())
                ->orderBy('id') // oldest remaining member
                ->first();

            if ($successor !== null) {
                $successor->update(['role' => CommunityRole::Admin]);

                continue;
            }

            // No other members: deleting the member would leave an empty, admin-less community.
            $community = Community::find($communityId);
            if ($community !== null) {
                $this->deleteCommunity->purge($community);
            }
        }
    }
}
