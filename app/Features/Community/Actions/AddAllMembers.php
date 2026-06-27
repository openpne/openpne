<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-add every member who isn't already in $community as a plain member (OpenPNE 3 "default
 * community" join-all). Existing memberships are left untouched, so admins/sub-admins keep their role.
 *
 * Returns the number actually added. Inserts are chunked (large sites) and idempotent against the
 * (community_id, member_id) unique key, so a concurrent join or a re-run neither duplicates nor throws.
 */
class AddAllMembers
{
    public function __invoke(Community $community): int
    {
        $communityId = $community->getKey();
        $now = now();
        $added = 0;

        Member::query()
            ->whereNotExists(function ($query) use ($communityId): void {
                $query->select(DB::raw(1))
                    ->from('community_members')
                    ->whereColumn('community_members.member_id', 'members.id')
                    ->where('community_members.community_id', $communityId);
            })
            ->select('id')
            ->chunkById(1000, function ($members) use ($communityId, $now, &$added): void {
                $rows = $members->map(fn (Member $member): array => [
                    'community_id' => $communityId,
                    'member_id' => $member->getKey(),
                    'role' => CommunityRole::Member->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                $added += DB::table('community_members')->insertOrIgnore($rows);
            });

        // Everyone is now a member, so any pending join requests for this community are redundant.
        DB::table('community_join_requests')->where('community_id', $communityId)->delete();

        return $added;
    }
}
