<?php

namespace App\Features\Member\Actions;

use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\GroupRole;
use App\Features\Member\Events\MemberWithdrawn;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SecurityLog;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Withdraw (permanently delete) a member. Admin-initiated: the panel guard authorizes, so there is
 * no per-actor check here — only the primary-member guard.
 *
 * Most of the member's rows are removed by the `members` FK cascade (friendships, friend_requests,
 * member_blocks, group_members, group_join_requests, group_event_members,
 * member_profiles, member_preferences); the avatar File and any personal access tokens, both linked
 * polymorphically and so outside the cascade, are removed by MemberObserver::deleting().
 * SET-NULL relations are deliberately retained with a null author — the member's comments on others'
 * content, authored topics/events, and sent/received messages stay so the other parties' views keep
 * rendering (a withdrawn-member placeholder fills the null).
 *
 * Three things the cascade cannot do, handled explicitly here:
 *  - Image File *bytes* of cascade-deleted content (the member's own diaries + their comments, and
 *    timeline posts) — the cascade drops the *_image link rows but never the File bytes. We route
 *    each through its own delete action's purge so the bytes go too.
 *  - Sole-admin groups — flattened roles mean no implicit successor; hand over or dissolve.
 *  - Owned AI accounts — `members.owner_member_id` is RESTRICT precisely so a cascade cannot take
 *    them silently; each is withdrawn first, through this same action.
 *
 * There is deliberately NO single wrapping transaction. The cores purge image bytes via the
 * FileObserver, which removes them irreversibly; that must stay outside any transaction that could
 * roll back (a rollback would restore the rows but not the bytes). Each core therefore runs
 * un-nested, exactly as the frontend calls it. The final member-row delete instead runs in a small
 * verify+delete transaction (lock the member row → assert zero memberships → delete), so a membership
 * racing in during the long purge phase can't survive the delete and strand a community admin-less.
 * MemberObserver defers the avatar-byte purge to DB::afterCommit, so a rollback of this transaction
 * leaves the File row and its bytes intact — the bytes are destroyed only once the delete is durable.
 * The per-community handover locks are the other transactions.
 */
class WithdrawMember
{
    public function __construct(
        private readonly DeleteDiary $deleteDiary,
        private readonly DeleteTimelinePost $deleteTimelinePost,
        private readonly DeleteGroup $deleteGroup,
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
        $wasAiAccount = $member->isAiAccount();

        // Retire the AI accounts this member owns before anything else: they are members too, with
        // seats and content of their own, and the FK refuses to let this row go while one survives.
        $this->drainAiAccounts($member);

        // Leave every community first (each under its own row lock), handing over sole-admin seats;
        // dissolve the leftover empty ones after their lock commits so their byte purge stays post-commit.
        $this->drainGroups($member);

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

        $this->deleteMemberRow($member);

        ViewerRelations::flush();

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

        MemberWithdrawn::dispatch($memberId, $name, $email, $locale, $wasAiAccount);
    }

    /**
     * Leave every community (each under its own row lock, handing over sole-admin seats) and dissolve
     * the ones left empty. Re-runnable: deleteMemberRow() calls it again if a membership raced in.
     */
    private function drainGroups(Member $member): void
    {
        foreach ($this->handOverAdminGroups($member) as $group) {
            $this->deleteGroup->purge($group);
        }
    }

    /**
     * Withdraw every AI account this member owns, through this same action so each gets the identical
     * treatment (no second delete path to keep in step). Not routed through DeleteAiAccount: that one
     * is the owner's deliberate retirement of a single account and logs it as such, where this is the
     * owner disappearing and taking them along.
     *
     * The recursion terminates at depth one — CreateAiAccount refuses to give an AI account an AI
     * account of its own — so no depth guard is needed. Re-runnable, like drainGroups(): deleteMemberRow()
     * calls it again if one raced in.
     */
    private function drainAiAccounts(Member $member): void
    {
        foreach ($member->aiAccounts()->get() as $aiAccount) {
            $this($aiAccount);
        }
    }

    /**
     * Delete the member row only once it holds no memberships and owns no AI account, closing the
     * window where either (a sole-admin membership from a transfer accepted mid-withdrawal, an
     * account created from another device) races in after the drain. A membership would then be
     * silently FK-cascaded away, stranding a community admin-less; an AI account would instead abort
     * the delete on the RESTRICT foreign key, which is loud but leaves the withdrawal half-done.
     *
     * While the member row is X-locked, a concurrent group_members INSERT for this member blocks on
     * InnoDB's FK parent-row share lock until we commit — after which that insert fails the FK. A
     * membership committed before we took the lock is caught by the locked count and drained again
     * (which hands over any admin seat under the community lock), then we retry.
     *
     * Convergence is the overwhelmingly normal case but not guaranteed: nothing pre-purges the member's
     * other sessions before this runs (self-withdrawal logs out only the current guard, and its session
     * purge happens after this action returns; admin-initiated withdrawal does no pre-purge), so another
     * device could in principle keep re-joining between drains. The cap bounds that pathological spin;
     * exhausting it throws — aborting loudly with the member's content already purged but the row
     * retained, so nothing is silently lost.
     */
    private function deleteMemberRow(Member $member): void
    {
        $id = $member->getKey();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $done = DB::transaction(function () use ($id): bool {
                $locked = Member::whereKey($id)->lockForUpdate()->first();
                if ($locked === null) {
                    return true; // already gone
                }

                $hasMembership = GroupMember::query()
                    ->where('member_id', $id)
                    ->lockForUpdate()
                    ->exists();
                if ($hasMembership) {
                    return false; // raced in before the lock — drain again below
                }

                // The same re-read for the other restrict: CreateAiAccount takes this very row's
                // lock first, so an account created after the drain is either already visible here
                // or blocked until this transaction commits — after which its FK has no parent.
                if ($locked->aiAccounts()->lockForUpdate()->exists()) {
                    return false;
                }

                $locked->delete(); // MemberObserver defers the avatar-byte purge to after this commit

                return true;
            });

            if ($done) {
                return;
            }

            $this->drainAiAccounts($member);
            $this->drainGroups($member);
        }

        throw new RuntimeException("Member {$id} still held memberships or AI accounts after the withdrawal drain cap.");
    }

    /**
     * Keep every community the member belongs to governable after withdrawal. Each membership is
     * removed under a lock on its community row, and whether a successor is needed is decided from the
     * role re-read *under that lock*, never from a snapshot taken before it (see the lock protocol in
     * AcceptAdminTransfer): a transfer accepted between enumeration and the lock can flip a non-admin
     * membership to admin, and branching on the stale role would strand the community admin-less — the
     * withdrawing nominee would become admin and then be cascade-deleted. When the departing role is
     * admin and no admin remains, the longest-tenured member is promoted (OpenPNE 3's oldest-becomes-
     * admin); groups with no members left are returned for post-commit dissolve (their byte purge
     * must run outside the lock transaction).
     *
     * All memberships are enumerated (not just admin ones) so each delete is serialized under the
     * community lock; deleteMemberRow() re-drains any that race in afterward, so the members FK cascade
     * never has to remove a membership (least of all a sole-admin one).
     *
     * @return array<int, Group>
     */
    private function handOverAdminGroups(Member $member): array
    {
        $memberships = GroupMember::query()
            ->where('member_id', $member->getKey())
            ->get();

        $toDissolve = [];

        foreach ($memberships as $membership) {
            $group = DB::transaction(function () use ($membership, $member): ?Group {
                $group = Group::whereKey($membership->group_id)->lockForUpdate()->first();
                if ($group === null) {
                    return null; // already dissolved by a concurrent withdrawal
                }

                // Re-read the seat under the lock; a concurrent path may already have removed it.
                $locked = GroupMember::query()
                    ->where('group_id', $group->getKey())
                    ->where('member_id', $member->getKey())
                    ->first();
                if ($locked === null) {
                    return null;
                }

                $locked->delete();

                // A transfer nominating the leaving member dies with the seat.
                if ((int) $group->pending_admin_member_id === (int) $member->getKey()) {
                    $group->pending_admin_member_id = null;
                    $group->save();
                }

                // Only an admin departure needs a successor; a plain/sub-admin seat just leaves.
                if ($locked->role !== GroupRole::Admin) {
                    return null;
                }

                $hasOtherAdmin = GroupMember::query()
                    ->where('group_id', $group->getKey())
                    ->where('role', GroupRole::Admin->value)
                    ->exists();

                if ($hasOtherAdmin) {
                    return null;
                }

                $successor = GroupMember::query()
                    ->where('group_id', $group->getKey())
                    ->orderBy('id') // longest-tenured remaining member
                    ->first();

                if ($successor !== null) {
                    $successor->update(['role' => GroupRole::Admin]);

                    return null;
                }

                return $group; // no members remain → dissolve after commit
            });

            if ($group !== null) {
                $toDissolve[] = $group;
            }
        }

        return $toDissolve;
    }
}
