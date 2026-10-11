<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Pins the plan the room list's mentions arm gets on each engine: the member-led index on SQLite,
 * where a member_id-only one would scan every mention of the viewer, and an index lookup on MySQL,
 * whose statistics pick the unique key led by the message id (docs/internals/ordering.md, "SQLite
 * foreign-key indexes").
 */
class UnreadMentionsPlanTest extends TalkTestCase
{
    public function test_the_mentions_arm_is_served_by_an_index_lookup(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);
        foreach (range(1, 3) as $ignored) {
            DB::table('group_message_mentions')->insert([
                'group_message_id' => GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $other->getKey()])->getKey(),
                'member_id' => $viewer->getKey(),
                'offset' => 0,
                'length' => 1,
            ]);
        }

        $query = app(JoinedTalkRooms::class)->ordered($viewer, true);
        $plan = $this->plan($query->toSql(), $query->getBindings());

        $this->assertMatchesRegularExpression(
            DB::getDriverName() === 'sqlite'
                ? '/group_message_mentions USING (COVERING )?INDEX group_message_mentions_member_id_group_message_id_index/'
                : '/index lookup on group_message_mentions using \S+/i',
            $plan,
            "the mentions arm is not read through an index:\n{$plan}",
        );
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function plan(string $sql, array $bindings): string
    {
        $rows = DB::getDriverName() === 'sqlite'
            ? DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings)
            : DB::select('EXPLAIN FORMAT=TREE '.$sql, $bindings);

        return collect($rows)->map(fn (object $row): string => implode(' ', array_map(strval(...), (array) $row)))->implode("\n");
    }
}
