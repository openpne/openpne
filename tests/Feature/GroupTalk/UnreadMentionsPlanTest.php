<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Models\GroupMessage;
use Illuminate\Support\Facades\DB;

/** The SQLite plan for the room list's mentions arm, read off EXPLAIN (docs/internals/ordering.md, "SQLite foreign-key indexes"). */
class UnreadMentionsPlanTest extends TalkTestCase
{
    public function test_the_mentions_arm_is_looked_up_by_both_key_columns(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MySQL plans a freshly filled table by statistics InnoDB gathers in the background, so only the SQLite plan is pinned.');
        }
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
        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))
            ->map(fn (object $row): string => implode(' ', array_map(strval(...), (array) $row)))
            ->implode("\n");

        // The search key, not only the index's name: a member_id-only prefix would read every mention of the viewer.
        $this->assertMatchesRegularExpression(
            '/group_message_mentions USING (COVERING )?INDEX group_message_mentions_member_id_group_message_id_index \(member_id=\? AND group_message_id=\?\)/',
            $plan,
            "the mentions arm is not looked up by its key columns:\n{$plan}",
        );
    }
}
