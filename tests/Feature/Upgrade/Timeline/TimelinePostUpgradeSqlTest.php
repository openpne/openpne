<?php

namespace Tests\Feature\Upgrade\Timeline;

use App\Support\Visibility;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\TimelinePostUpgrade;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/**
 * The thread-starter step against the real OpenPNE 3 DDL; MySQL only. The activity flag scale is
 * 0..3 with Open at 0, unlike the diary scale, so the identity map is pinned value by value.
 */
class TimelinePostUpgradeSqlTest extends TestCase
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

    public function test_copies_thread_starters_with_the_identity_audience_map(): void
    {
        $member = $this->activeMember();
        foreach (Visibility::cases() as $visibility) {
            $this->seedActivity(10 + $visibility->value, $member->id, ['public_flag' => $visibility->value, 'body' => "flag {$visibility->value}"]);
        }

        $this->runStep();

        $this->assertDatabaseCount('timeline_posts', 4);
        foreach (Visibility::cases() as $visibility) {
            $this->assertDatabaseHas('timeline_posts', [
                'id' => 10 + $visibility->value,
                'member_id' => $member->id,
                'in_reply_to_id' => null,
                'body' => "flag {$visibility->value}",
                'visibility' => $visibility->value,
                'created_at' => '2015-05-06 07:08:09',
            ]);
        }
    }

    public function test_a_starter_scoped_to_a_community_or_anything_else_does_not_land_here(): void
    {
        $member = $this->activeMember();
        $this->seedSourceCommunity(5);
        $this->seedActivity(1, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedActivity(2, $member->id, ['foreign_table' => 'diary', 'foreign_id' => 9]);
        $this->seedActivity(3, $member->id);

        $this->runStep();

        $this->assertSame([3], DB::table('timeline_posts')->pluck('id')->all());
    }

    public function test_a_reply_whose_parent_is_missing_lands_as_a_post_of_its_own(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(7, $member->id, ['in_reply_to_activity_id' => 999, 'public_flag' => 2]);

        $this->runStep();

        $this->assertDatabaseHas('timeline_posts', ['id' => 7, 'in_reply_to_id' => null, 'visibility' => Visibility::Friends->value]);
    }

    public function test_a_template_row_copies_its_stored_body_for_the_pass_to_rewrite(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(8, $member->id, ['body' => '[Diary] stored'] + $this->templateRow('diary', ['%1%' => 'stored'], '@diary_show?id=3'));

        $this->runStep();

        $this->assertDatabaseHas('timeline_posts', ['id' => 8, 'body' => '[Diary] stored']);
    }

    public function test_an_unknown_flag_fails_the_copy(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(9, $member->id, ['public_flag' => 4]);

        $this->expectException(QueryException::class);
        $this->runStep();
    }

    private function runStep(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new TimelinePostUpgrade));
    }
}
