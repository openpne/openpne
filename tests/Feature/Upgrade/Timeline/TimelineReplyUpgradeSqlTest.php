<?php

namespace Tests\Feature\Upgrade\Timeline;

use App\Support\Visibility;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\TimelinePostUpgrade;
use App\Upgrade\Steps\TimelineReplyUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/**
 * The reply step after the starter step, against the real OpenPNE 3 DDL; MySQL only. Fixtures reach
 * past the fleet's shape on purpose: nested replies, a scoped reply under an unscoped root and the
 * reverse, a reply under a parent the source lost.
 */
class TimelineReplyUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities, SeedsSourceMembers;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceMemberTable();
        $this->createSourceActivityTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceActivityTables();
            $this->dropSourceMemberTable();
        }

        parent::tearDown();
    }

    public function test_a_reply_attaches_to_its_root_with_the_roots_audience(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id, ['public_flag' => 2]);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1, 'public_flag' => 1, 'body' => 'reply']);

        $this->runSteps();

        $this->assertDatabaseHas('timeline_posts', ['id' => 2, 'in_reply_to_id' => 1, 'visibility' => Visibility::Friends->value, 'body' => 'reply']);
    }

    public function test_nested_replies_attach_to_the_root_up_to_the_depth_limit(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id);
        foreach ([2, 3, 4, 5, 6] as $id) {
            $this->seedActivity($id, $member->id, ['in_reply_to_activity_id' => $id - 1]);
        }

        $this->runSteps();

        // 2..5 are one to four hops from the root; 6 is five hops away and is not migrated.
        foreach ([2, 3, 4, 5] as $id) {
            $this->assertDatabaseHas('timeline_posts', ['id' => $id, 'in_reply_to_id' => 1]);
        }
        $this->assertDatabaseMissing('timeline_posts', ['id' => 6]);
    }

    public function test_the_root_decides_the_landing_not_the_replys_own_scope(): void
    {
        $member = $this->activeMember();
        $this->seedSourceCommunity(5);
        $this->seedActivity(1, $member->id);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1, 'foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedActivity(3, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedActivity(4, $member->id, ['in_reply_to_activity_id' => 3]);

        $this->runSteps();

        $this->assertDatabaseHas('timeline_posts', ['id' => 2, 'in_reply_to_id' => 1]);
        $this->assertDatabaseMissing('timeline_posts', ['id' => 3]);
        $this->assertDatabaseMissing('timeline_posts', ['id' => 4]);
    }

    public function test_a_reply_to_a_post_whose_own_parent_is_missing_attaches_to_that_post(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(7, $member->id, ['in_reply_to_activity_id' => 999]);
        $this->seedActivity(8, $member->id, ['in_reply_to_activity_id' => 7]);

        $this->runSteps();

        $this->assertDatabaseHas('timeline_posts', ['id' => 7, 'in_reply_to_id' => null]);
        $this->assertDatabaseHas('timeline_posts', ['id' => 8, 'in_reply_to_id' => 7]);
    }

    private function runSteps(): void
    {
        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new TimelinePostUpgrade));
        DB::statement($compiler->compile(new TimelineReplyUpgrade));
    }
}
