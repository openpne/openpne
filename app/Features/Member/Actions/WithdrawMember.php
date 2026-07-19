<?php

namespace App\Features\Member\Actions;

use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Community\CommunityRole;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Member\Events\MemberWithdrawn;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SecurityLog;
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

        // Capture the address/name/locale the withdrawal mails need before the row is gone; the event
        // carries only these scalars (dispatched post-delete).
        $memberId = (int) $member->getKey();
        $name = (string) $member->name;
        $email = (string) $member->email;
        $locale = $member->locale ?? (string) config('app.locale');

        // Leave every community first (each under its own row lock), handing over sole-admin seats;
        // dissolve the leftover empty ones after their lock commits so their byte purge stays post-commit.
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

        // Logged here once so it covers both callers (self-withdrawal and the Filament DeleteAction),
        // and before the event dispatch — enqueueing its listeners is fallible and must not suppress
        // the audit record of an already-durable deletion. The self path has already logged the member
        // out, so only an admin remains on a guard here.
        $adminUsername = auth('admin')->user()?->username;
        SecurityLog::event('member.withdrawn', [
            'member_id' => $memberId,
            'actor' => $adminUsername === null ? 'self' : 'admin',
            'admin_username' => $adminUsername,
        ]);

        MemberWithdrawn::dispatch($memberId, $name, $email, $locale);
    }

    /**
     * Keep every community the member belongs to governable after withdrawal. Each membership is
     * removed under a lock on its community row, and whether a successor is needed is decided from the
     * role re-read *under that lock*, never from a snapshot taken before it (see the lock protocol in
     * AcceptAdminTransfer): a transfer accepted between enumeration and the lock can flip a non-admin
     * membership to admin, and branching on the stale role would strand the community admin-less — the
     * withdrawing nominee would become admin and then be cascade-deleted. When the departing role is
     * admin and no admin remains, the longest-tenured member is promoted (OpenPNE 3's oldest-becomes-
     * admin); communities with no members left are returned for post-commit dissolve (their byte purge
     * must run outside the lock transaction).
     *
     * All memberships are enumerated (not just admin ones) so each delete is serialized here rather
     * than left to the members FK cascade — the cascade only backstops rows created after enumeration.
     *
     * @return array<int, Community>
     */
    private function handOverAdminCommunities(Member $member): array
    {
        $memberships = CommunityMember::query()
            ->where('member_id', $member->getKey())
            ->get();

        $toDissolve = [];

        foreach ($memberships as $membership) {
            $community = DB::transaction(function () use ($membership, $member): ?Community {
                $community = Community::whereKey($membership->community_id)->lockForUpdate()->first();
                if ($community === null) {
                    return null; // already dissolved by a concurrent withdrawal
                }

                // Re-read the seat under the lock; a concurrent path may already have removed it.
                $locked = CommunityMember::query()
                    ->where('community_id', $community->getKey())
                    ->where('member_id', $member->getKey())
                    ->first();
                if ($locked === null) {
                    return null;
                }

                $locked->delete();

                // A transfer nominating the leaving member dies with the seat.
                if ((int) $community->pending_admin_member_id === (int) $member->getKey()) {
                    $community->pending_admin_member_id = null;
                    $community->save();
                }

                // Only an admin departure needs a successor; a plain/sub-admin seat just leaves.
                if ($locked->role !== CommunityRole::Admin) {
                    return null;
                }

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
