<?php

namespace App\Features\Group\Actions;

use App\Features\Group\GroupRole;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-add every member who isn't already in $group as a plain member (OpenPNE 3 "default
 * group" join-all). Existing memberships are left untouched, so admins/sub-admins keep their role.
 *
 * Returns the number actually added. Inserts are chunked (large sites) and idempotent against the
 * (group_id, member_id) unique key, so a concurrent join or a re-run neither duplicates nor throws.
 */
class AddAllMembers
{
    public function __invoke(Group $group): int
    {
        // Outside the group-row lock protocol (see AcceptAdminTransfer): this only inserts plain
        // Member rows and never touches any admin/sub-admin role or pending_admin_member_id.
        $groupId = $group->getKey();
        $now = now();
        $added = 0;
        // One read for the whole sweep: every row added here joins the same group at the same
        // moment, so they share a cursor. This is the one membership path with no model behind it,
        // so the snapshot has to be written into the row literal (TalkReadCursor).
        $cursor = TalkReadCursor::snapshot((int) $groupId);

        Member::query()
            ->whereNotExists(function ($query) use ($groupId): void {
                $query->select(DB::raw(1))
                    ->from('group_members')
                    ->whereColumn('group_members.member_id', 'members.id')
                    ->where('group_members.group_id', $groupId);
            })
            ->select('id')
            ->chunkById(1000, function ($members) use ($groupId, $now, $cursor, &$added): void {
                $rows = $members->map(fn (Member $member): array => [
                    'group_id' => $groupId,
                    'member_id' => $member->getKey(),
                    'role' => GroupRole::Member->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                    ...$cursor,
                ])->all();

                $added += DB::table('group_members')->insertOrIgnore($rows);
            });

        // Everyone is now a member, so any pending join requests for this group are redundant.
        DB::table('group_join_requests')->where('group_id', $groupId)->delete();

        ViewerRelations::flush();

        return $added;
    }
}
