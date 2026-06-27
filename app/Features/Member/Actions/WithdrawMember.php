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
 *  - Sole-admin communities — flattened roles mean no implicit successor; hand over or dissolve.
 *
 * There is deliberately NO single wrapping transaction. The cores purge image bytes via the
 * FileObserver, which removes them irreversibly; that must stay outside any transaction that could
 * roll back (a rollback would restore the rows but not the bytes). Each core therefore runs
 * un-nested, exactly as the frontend calls it, and the member-row delete — with MemberObserver's
 * avatar purge — runs un-nested too. The only transactions are the per-community handover locks.
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

        // Resolve sole-admin communities first (each under its own row lock); dissolve the leftover
        // empty ones after their lock commits so their byte purge stays post-commit.
        foreach ($this->handOverAdminCommunities($member) as $community) {
            $this->deleteCommunity->purge($community);
        }

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
    }

    /**
     * For every community the member administers, keep it governable after withdrawal: under a lock
     * on the community row, relinquish the member's admin seat, then — if no admin remains — promote
     * the longest-tenured remaining member (OpenPNE 3's oldest-becomes-admin). The lock plus the
     * in-lock relinquishment serialize concurrent admin withdrawals: without it, two sole-admins
     * leaving at once could each see the other still present and skip handover, stranding the
     * community admin-less. Communities with no members left are returned for post-commit dissolve
     * (their byte purge must run outside the lock transaction).
     *
     * @return array<int, Community>
     */
    private function handOverAdminCommunities(Member $member): array
    {
        $adminMemberships = CommunityMember::query()
            ->where('member_id', $member->getKey())
            ->where('role', CommunityRole::Admin->value)
            ->get();

        $toDissolve = [];

        foreach ($adminMemberships as $membership) {
            $community = DB::transaction(function () use ($membership, $member): ?Community {
                $community = Community::whereKey($membership->community_id)->lockForUpdate()->first();
                if ($community === null) {
                    return null; // already dissolved by a concurrent withdrawal
                }

                // Give up the leaving member's seat under the lock so the successor check below sees
                // the post-departure state (the members cascade would otherwise drop it only later).
                CommunityMember::query()
                    ->where('community_id', $community->getKey())
                    ->where('member_id', $member->getKey())
                    ->delete();

                $hasOtherAdmin = CommunityMember::query()
                    ->where('community_id', $community->getKey())
                    ->where('role', CommunityRole::Admin->value)
                    ->exists();

                if ($hasOtherAdmin) {
                    return null;
                }

                $successor = CommunityMember::query()
                    ->where('community_id', $community->getKey())
                    ->orderBy('id') // longest-tenured remaining member
                    ->first();

                if ($successor !== null) {
                    $successor->update(['role' => CommunityRole::Admin]);

                    return null;
                }

                return $community; // no members remain → dissolve after commit
            });

            if ($community !== null) {
                $toDissolve[] = $community;
            }
        }

        return $toDissolve;
    }
}
