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
 * There is deliberately no wrapping transaction: the purges below destroy image bytes irreversibly,
 * so they must not sit inside anything that can roll back. The caller authorizes; nothing here
 * checks the actor.
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
        // Defensive: the admin UI hides the action for id 1, so reaching here is a programming error.
        if ((int) $member->getKey() === 1) {
            throw new RuntimeException('The primary member cannot be withdrawn.');
        }

        // Read before the row is gone: the event, dispatched after the delete, carries only these scalars.
        $memberId = (int) $member->getKey();
        $name = (string) $member->name;
        $email = (string) $member->email;
        $locale = $member->locale ?? (string) config('app.locale');
        $wasAiAccount = $member->isAiAccount();

        // Before anything else: `owner_member_id` is RESTRICT, so this row cannot go while an owned
        // account survives.
        $this->drainAiAccounts($member);

        $this->drainGroups($member);

        // purge(), not the cascade: the cascade drops the `*_image` link rows but never the File bytes.
        foreach ($member->diaries()->get() as $diary) {
            $this->deleteDiary->purge($diary);
        }

        // Top-level only: an image lives only on a top-level post, and a reply cascades with its
        // parent or with the member row.
        $topLevelPosts = TimelinePost::query()
            ->where('member_id', $member->getKey())
            ->whereNull('in_reply_to_id')
            ->get();

        foreach ($topLevelPosts as $post) {
            ($this->deleteTimelinePost)($post);
        }

        $this->deleteMemberRow($member);

        ViewerRelations::flush();

        // The self path has already logged the member out, so a guard here means an admin actor.
        $adminUsername = auth('admin')->user()?->username;

        // Before the dispatch: enqueueing listeners is fallible and must not suppress the audit record.
        SecurityLog::event('member.withdrawn', [
            'member_id' => $memberId,
            'actor' => $adminUsername === null ? 'self' : 'admin',
            'admin_username' => $adminUsername,
        ]);

        MemberWithdrawn::dispatch($memberId, $name, $email, $locale, $wasAiAccount);
    }

    /**
     * The dissolve runs after each group's lock commits, so its byte purge stays outside that
     * transaction. Re-runnable: deleteMemberRow() calls it again if a membership raced in.
     */
    private function drainGroups(Member $member): void
    {
        foreach ($this->handOverAdminGroups($member) as $group) {
            $this->deleteGroup->purge($group);
        }
    }

    /**
     * The recursion ends at depth one because CreateAiAccount refuses an AI account an AI account of
     * its own, so there is no depth guard. Not routed through DeleteAiAccount, which logs the owner's
     * deliberate retirement of one account.
     */
    private function drainAiAccounts(Member $member): void
    {
        foreach ($member->aiAccounts()->get() as $aiAccount) {
            $this($aiAccount);
        }
    }

    /**
     * The delete waits until the member holds no membership and owns no AI account. A membership
     * would otherwise be FK-cascaded away silently and strand a group admin-less, while an AI account
     * would abort the delete on its RESTRICT foreign key.
     */
    private function deleteMemberRow(Member $member): void
    {
        $id = $member->getKey();

        // Nothing pre-purges the member's other sessions, so a device could keep re-joining; the cap
        // bounds that spin and throws with the content purged and the row kept.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $done = DB::transaction(function () use ($id): bool {
                // While this row is X-locked a concurrent `group_members` insert blocks on InnoDB's
                // FK parent-row lock until we commit, after which it fails the FK.
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
     * Each membership is removed under the group row's lock and the departing role is re-read there,
     * never from the enumeration snapshot (docs/internals/group-boards.md, "The group row is the
     * lock"). Every membership is enumerated, not only the admin ones, so each delete is serialized
     * under that lock.
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
