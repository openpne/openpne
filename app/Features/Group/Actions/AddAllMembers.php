<?php

namespace App\Features\Group\Actions;

use App\Features\Group\GroupRole;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent against the `(group_id, member_id)` unique key, so a concurrent join or a re-run
 * neither duplicates nor throws.
 */
class AddAllMembers
{
    /** At up to nine columns a row, this many rows stay under the 999 bound parameters SQLite before 3.32 takes in one statement. */
    public const INSERT_ROWS = 100;

    public function __invoke(Group $group): int
    {
        // Outside the group-row lock protocol: this writes plain Member rows only
        // (docs/internals/group-boards.md, "The group row is the lock").
        $groupId = $group->getKey();
        $now = now();
        $added = 0;
        // One read for the whole sweep: every row added here joins at the same moment, so they
        // share a cursor (TalkReadCursor).
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

                DB::transaction(function () use ($rows, &$added): void {
                    foreach (array_chunk($rows, self::INSERT_ROWS) as $part) {
                        $added += DB::table('group_members')->insertOrIgnore($part);
                    }
                });
            });

        // Everyone is now a member, so any pending join requests for this group are redundant.
        DB::table('group_join_requests')->where('group_id', $groupId)->delete();

        ViewerRelations::flush();

        return $added;
    }
}
