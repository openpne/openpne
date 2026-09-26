<?php

namespace Tests\Concerns;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Notifications\CommentReason;
use Illuminate\Support\Facades\DB;

/** Fixture verbs for a recipients query: the people a notification must leave out, one line each. */
trait ShapesAudience
{
    protected function banned(): Member
    {
        return Member::factory()->create(['is_login_rejected' => true]);
    }

    protected function joined(Group $group, ?Member $member = null, GroupRole $role = GroupRole::Member, bool $muted = false): Member
    {
        $member ??= Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
            'is_talk_muted' => $muted,
        ]);

        return $member;
    }

    protected function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $blocked->getKey()]);
    }

    protected function befriend(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    /** @return list<int> */
    protected function keys(Member ...$members): array
    {
        $ids = array_map(fn (Member $member): int => (int) $member->getKey(), $members);
        sort($ids);

        return $ids;
    }

    /**
     * @param  iterable<Member|array{0: Member, 1: CommentReason}>  $recipients
     * @return list<int>
     */
    protected function idsOf(iterable $recipients): array
    {
        $ids = [];
        foreach ($recipients as $recipient) {
            $ids[] = (int) ($recipient instanceof Member ? $recipient : $recipient[0])->getKey();
        }
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<array{0: Member, 1: CommentReason}>  $recipients
     * @return list<array{0: int, 1: CommentReason}>
     */
    protected function reasonsOf(array $recipients): array
    {
        $pairs = array_map(fn (array $pair): array => [(int) $pair[0]->getKey(), $pair[1]], $recipients);
        usort($pairs, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $pairs;
    }
}
