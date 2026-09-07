<?php

namespace Tests\Feature\Upgrade\GroupTalk;

use App\Models\Group;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\GroupMessageUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/** The community-thread step against the real OpenPNE 3 DDL; MySQL only. */
class GroupMessageUpgradeSqlTest extends TestCase
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
        Group::factory()->create(['id' => 5]);
        $this->seedSourceCommunity(5);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceActivityTables();
            $this->dropSourceMemberTable();
        }

        parent::tearDown();
    }

    public function test_a_community_thread_lands_with_every_reply_attached_to_the_root(): void
    {
        [$author, $other] = $this->activeMembers(2);
        $this->seedActivity(1, $author->id, ['foreign_table' => 'community', 'foreign_id' => 5, 'body' => 'root', 'created_at' => '2015-05-06 07:08:09']);
        foreach ([2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5] as $id => $parent) {
            $this->seedActivity($id, $other->id, ['in_reply_to_activity_id' => $parent, 'foreign_table' => 'community', 'foreign_id' => 5, 'public_flag' => 3]);
        }

        $this->runStep();

        // 2..5 are one to four hops from the root; 6 is five and stays behind (ActivityThread::MAX_DEPTH).
        $this->assertSame([1, 2, 3, 4, 5], DB::table('group_messages')->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseHas('group_messages', ['id' => 1, 'group_id' => 5, 'member_id' => $author->id, 'in_reply_to_id' => null, 'body' => 'root', 'created_at' => '2015-05-06 07:08:09']);
        foreach ([2, 3, 4, 5] as $id) {
            $this->assertDatabaseHas('group_messages', ['id' => $id, 'group_id' => 5, 'member_id' => $other->id, 'in_reply_to_id' => 1]);
        }
    }

    public function test_the_root_decides_the_landing_not_the_replys_own_scope(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1]); // unscoped reply under a community root
        $this->seedActivity(3, $member->id); // timeline root
        $this->seedActivity(4, $member->id, ['in_reply_to_activity_id' => 3, 'foreign_table' => 'community', 'foreign_id' => 5]);
        Group::factory()->create(['id' => 6]);
        $this->seedSourceCommunity(6);
        $this->seedActivity(5, $member->id, ['in_reply_to_activity_id' => 1, 'foreign_table' => 'community', 'foreign_id' => 6]); // names another live community

        $this->runStep();

        $this->assertSame([1, 2, 5], DB::table('group_messages')->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseHas('group_messages', ['id' => 2, 'group_id' => 5, 'in_reply_to_id' => 1]);
        $this->assertDatabaseHas('group_messages', ['id' => 5, 'group_id' => 5, 'in_reply_to_id' => 1]);
    }

    public function test_a_reply_whose_parent_is_missing_starts_a_thread_in_its_own_community(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(7, $member->id, ['in_reply_to_activity_id' => 999, 'foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedActivity(8, $member->id, ['in_reply_to_activity_id' => 999]); // unscoped: the timeline's

        $this->runStep();

        $this->assertSame([7], DB::table('group_messages')->pluck('id')->all());
        $this->assertDatabaseHas('group_messages', ['id' => 7, 'group_id' => 5, 'in_reply_to_id' => null]);
    }

    public function test_a_thread_whose_community_is_gone_or_was_not_for_every_member_stays_behind(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id, ['foreign_table' => 'community', 'foreign_id' => 6]); // no community 6 in the source
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1, 'foreign_table' => 'community', 'foreign_id' => 6]);
        foreach ([0, 2, 3] as $flag) {
            $this->seedActivity(10 + $flag, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5, 'public_flag' => $flag]);
            $this->seedActivity(20 + $flag, $member->id, ['in_reply_to_activity_id' => 10 + $flag, 'foreign_table' => 'community', 'foreign_id' => 5, 'public_flag' => 1]);
        }
        $this->seedActivity(30, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5, 'body' => '[Community Topic] stored']
            + $this->templateRow('community_topic', ['%1%' => 'Runners', '%2%' => 'stored'], '@communityTopic_show?id=3'));

        $this->runStep();

        $this->assertSame([30], DB::table('group_messages')->pluck('id')->all());
        $this->assertDatabaseHas('group_messages', ['id' => 30, 'body' => '[Community Topic] stored']);
    }

    private function runStep(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new GroupMessageUpgrade));
    }
}
