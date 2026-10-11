<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Models\GroupMessage;
use Illuminate\Support\Facades\DB;

/** Each engine's plan for the room list's mentions arm, read off EXPLAIN (docs/internals/ordering.md, "SQLite foreign-key indexes"). */
class UnreadMentionsPlanTest extends TalkTestCase
{
    public function test_the_mentions_arm_is_looked_up_by_the_key_each_engine_searches_on(): void
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

        // On SQLite a member_id-only prefix would read every mention of the viewer; MySQL may answer the EXISTS from either equality index, never a scan.
        $this->assertMatchesRegularExpression(
            DB::getDriverName() === 'sqlite'
                ? '/group_message_mentions USING (COVERING )?INDEX group_message_mentions_member_id_group_message_id_index \(member_id=\? AND group_message_id=\?\)/'
                : '/index lookup on group_message_mentions using (group_message_mentions_group_message_id_offset_unique \(group_message_id=group_messages\.id\)|group_message_mentions_member_id_foreign \(member_id=\d+\))/i',
            $plan,
            "the mentions arm is not looked up by its key columns:\n{$plan}",
        );
        $this->assertDoesNotMatchRegularExpression('/(table scan on|SCAN) group_message_mentions/i', $plan);
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
