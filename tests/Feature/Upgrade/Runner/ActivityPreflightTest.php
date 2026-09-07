<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\ActivityPreflight;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\TimelinePostUpgrade;
use App\Upgrade\Steps\TimelineReplyUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/** Every activity disposition the routing applies is counted here first; MySQL only. */
class ActivityPreflightTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities, SeedsSourceMembers;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The preflight counts over the OpenPNE 3 source DDL on MySQL.');
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

    public function test_a_clean_source_reports_nothing(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1]);

        $report = (new ActivityPreflight)->inspect('', null);

        $this->assertSame([], $report->errors);
        $this->assertSame([], $report->warnings);
    }

    public function test_an_unknown_flag_and_a_crowded_activity_are_errors(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id, ['public_flag' => 7]);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1, 'public_flag' => 9]); // a reply's own flag is never read
        $this->seedActivity(3, $member->id);
        for ($i = 1; $i <= 256; $i++) {
            $this->seedActivityImage($i, 3, 1000 + $i);
        }
        $this->seedActivityImage(300, 3, null); // URL-only rows do not count toward the slot cap

        $report = (new ActivityPreflight)->inspect('', null);

        $this->assertSame([
            ActivityPreflight::unknownPublicFlagMessage(1, [1]),
            ActivityPreflight::tooManyImagesMessage(1, [3]),
        ], $report->errors);
    }

    public function test_every_disposition_is_a_warning_with_its_first_ids(): void
    {
        $member = $this->activeMember();
        $this->seedSourceCommunity(5);
        // 1 ← 2 ← 3 ← 4 ← 5 ← 6: 3..5 are re-parented, 6 is out of reach.
        $this->seedActivity(1, $member->id);
        foreach ([2, 3, 4, 5, 6] as $id) {
            $this->seedActivity($id, $member->id, ['in_reply_to_activity_id' => $id - 1]);
        }
        $this->seedActivity(7, $member->id, ['in_reply_to_activity_id' => 999]);
        $this->seedActivity(8, $member->id, ['foreign_table' => 'diary', 'foreign_id' => 1]);
        $this->seedActivity(9, $member->id, ['foreign_table' => 'community', 'foreign_id' => 404]);
        $this->seedActivity(10, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5, 'public_flag' => 0]);
        $this->seedActivity(11, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5, 'public_flag' => 2]);
        $this->seedActivity(12, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedActivity(13, $member->id, ['in_reply_to_activity_id' => 12]); // scope-less reply under a community root
        $this->seedActivity(14, $member->id, ['in_reply_to_activity_id' => 1, 'foreign_table' => 'community', 'foreign_id' => 5]); // the reverse
        $this->seedActivity(15, $member->id, ['in_reply_to_activity_id' => 12, 'foreign_table' => 'community', 'foreign_id' => 6]); // another community
        $this->seedActivityImage(1, 1, null, 'http://img.example/a.png');
        $this->seedActivity(16, $member->id, $this->templateRow('diary', ['%1%' => 't'], '@diary_show?id=1'));
        $this->seedActivity(17, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5] + $this->templateRow('community_topic', ['%1%' => 'g', '%2%' => 'n'], '@communityTopic_show?id=1'));
        $this->seedActivity(18, $member->id, ['foreign_table' => 'diary'] + $this->templateRow('diary', ['%1%' => 't'], '@diary_show?id=1'));
        $this->seedActivity(19, $member->id, $this->templateRow('friend_link', ['%1%' => 't'], '@diary_show?id=1'));

        $report = (new ActivityPreflight)->inspect('', null);

        $this->assertSame([], $report->errors);
        $this->assertSame([
            ActivityPreflight::deepReplyMessage(3, [3, 4, 5]),
            ActivityPreflight::unresolvableReplyMessage(1, [6]),
            ActivityPreflight::danglingReplyMessage(1, [7]),
            ActivityPreflight::otherScopeMessage('diary', 2, [8, 18]),
            ActivityPreflight::orphanGroupThreadMessage(1, [9]),
            ActivityPreflight::nonMembersGroupThreadMessage(0, 1, [10]),
            ActivityPreflight::nonMembersGroupThreadMessage(2, 1, [11]),
            ActivityPreflight::crossScopeReplyMessage(3, [13, 14, 15]),
            ActivityPreflight::uriOnlyImageMessage(1, [1]),
            ActivityPreflight::templateMessage('community_topic', 'group', 1),
            ActivityPreflight::templateMessage('diary', 'none', 1),
            ActivityPreflight::templateMessage('diary', 'timeline', 1),
            ActivityPreflight::templateMessage('friend_link', 'timeline', 1),
        ], $report->warnings);
    }

    public function test_an_error_aborts_the_run_before_any_write_and_a_warning_does_not(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id, ['public_flag' => 7]);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 999]);

        [$ok, $output] = $this->runActivitySteps();

        $this->assertFalse($ok);
        $this->assertStringContainsString('ERROR '.ActivityPreflight::unknownPublicFlagMessage(1, [1]), $output);
        $this->assertStringContainsString('WARN '.ActivityPreflight::danglingReplyMessage(1, [2]), $output);
        $this->assertDatabaseCount('timeline_posts', 0);

        DB::table('activity_data')->where('id', 1)->update(['public_flag' => 1]);
        [$ok, $output] = $this->runActivitySteps();

        $this->assertTrue($ok, $output);
        $this->assertDatabaseCount('timeline_posts', 2);
    }

    /** @return array{bool, string} */
    private function runActivitySteps(): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new TimelinePostUpgrade, new TimelineReplyUpgrade]))
            ->run(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$ok, implode("\n", $lines)];
    }
}
