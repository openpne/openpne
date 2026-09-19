<?php

namespace Tests\Feature\Upgrade\Reactions;

use App\Features\Reactions\ReactionVocabulary;
use App\Models\Group;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\NicePreflight;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\GroupMessageReactionUpgrade;
use App\Upgrade\Steps\GroupMessageUpgrade;
use App\Upgrade\Steps\TimelinePostUpgrade;
use App\Upgrade\Steps\TimelineReactionUpgrade;
use App\Upgrade\Steps\TimelineReplyUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Concerns\SeedsSourceNice;
use Tests\TestCase;

/** The opLikePlugin like on an activity, following the activity's landing; MySQL only. */
class NiceReactionUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities, SeedsSourceMembers, SeedsSourceNice;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceMemberTable();
        $this->createSourceActivityTables();
        $this->createSourceNiceTable();
        Group::factory()->create(['id' => 5]);
        $this->seedSourceCommunity(5);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceNiceTable();
            $this->dropSourceActivityTables();
            $this->dropSourceMemberTable();
        }

        parent::tearDown();
    }

    public function test_a_like_lands_where_its_activity_did(): void
    {
        [$author, $fan] = $this->activeMembers(2);
        $this->seedActivity(1, $author->id); // timeline root
        $this->seedActivity(2, $author->id, ['in_reply_to_activity_id' => 1]); // timeline reply
        $this->seedActivity(3, $author->id, ['foreign_table' => 'community', 'foreign_id' => 5]); // group root
        $this->seedActivity(4, $author->id, ['in_reply_to_activity_id' => 3, 'foreign_table' => 'community', 'foreign_id' => 5]);
        foreach ([1, 2, 3, 4] as $i) {
            $this->seedNice($i, $fan->id, 'A', $i, "2016-01-0{$i} 00:00:00");
        }

        $this->runSteps();

        $this->assertSame(
            [[1, 'timelinePost', 1], [2, 'timelinePost', 2], [3, 'groupMessage', 3], [4, 'groupMessage', 4]],
            DB::table('reactions')->orderBy('id')->get()->map(fn (object $r): array => [(int) $r->id, $r->reactable_type, (int) $r->reactable_id])->all(),
        );
        $this->assertDatabaseHas('reactions', ['id' => 1, 'member_id' => $fan->id, 'emoji' => ReactionVocabulary::LIKE, 'created_at' => '2016-01-01 00:00:00']);
    }

    public function test_a_like_on_an_activity_that_is_not_migrated_is_left_behind_and_counted(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id, ['foreign_table' => 'community', 'foreign_id' => 99]); // community gone
        $this->seedActivity(2, $member->id);
        $this->seedNice(1, $member->id, 'A', 1);
        $this->seedNice(2, $member->id, 'A', 2);
        $this->seedNice(3, $member->id, 'A', 404); // activity gone

        $this->seedNice(4, $member->id, 'D', 1);
        $this->seedNice(5, $member->id, 'e', 1);

        $lines = [];
        $ran = (new UpgradeRunner(new InsertSelectCompiler, $this->steps()))->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertTrue($ran, implode("\n", $lines));
        $this->assertSame([2], DB::table('reactions')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertContains('WARN '.NicePreflight::unmigratedActivityLikeMessage(2, [1, 3]), $lines);
        $this->assertContains('WARN '.NicePreflight::otherLikeMessage('diaries', 1, [4]), $lines);
        $this->assertContains('WARN '.NicePreflight::otherLikeMessage('event comments', 1, [5]), $lines);
    }

    public function test_two_likes_by_one_member_on_one_activity_abort_before_any_write(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id);
        // A 0.9-era source: the unique index over (member, table, id) arrived later.
        DB::statement('ALTER TABLE `nice` DROP INDEX `member_id_foreign_table_foreign_id_UNIQUE_idx`');
        $this->seedNice(1, $member->id, 'A', 1);
        $this->seedNice(2, $member->id, 'A', 1);
        $this->seedActivity(9, $member->id, ['foreign_table' => 'diary']); // not migrated: its twins are no abort
        $this->seedNice(3, $member->id, 'A', 9);
        $this->seedNice(4, $member->id, 'A', 9);
        $this->seedNice(5, $member->id, 'x', 1); // a letter opLikePlugin never writes

        $lines = [];
        $ran = (new UpgradeRunner(new InsertSelectCompiler, $this->steps()))->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertFalse($ran);
        $this->assertContains('ERROR '.NicePreflight::duplicateLikeMessage(1, [2]), $lines);
        $this->assertContains('WARN '.NicePreflight::unknownTableLikeMessage('x', 1, 5), $lines);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_likes_on_anything_but_an_activity_are_not_this_steps(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id);
        $this->seedNice(1, $member->id, 'D', 1);
        $this->seedNice(2, $member->id, 'd', 1);
        $this->seedNice(3, $member->id, 't', 1);
        $this->seedNice(4, $member->id, 'e', 1);

        $this->runSteps();

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_verify_agrees_on_both_targets(): void
    {
        [$author, $fan] = $this->activeMembers(2);
        $this->seedActivity(1, $author->id);
        $this->seedActivity(2, $author->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedNice(1, $fan->id, 'A', 1);
        $this->seedNice(2, $fan->id, 'A', 2);
        $this->seedNice(3, $author->id, 'A', 2);
        $this->seedNice(4, $fan->id, 'D', 1);

        $steps = $this->steps();
        (new UpgradeRunner(new InsertSelectCompiler, $steps))->run(new RunOptions);
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, $steps))->verify(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $out = implode("\n", $lines);
        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS TimelineReactionUpgrade', $out);
        $this->assertStringContainsString('PASS GroupMessageReactionUpgrade', $out);
        $this->assertDatabaseCount('reactions', 3);
    }

    private function runSteps(): void
    {
        foreach ($this->steps() as $step) {
            DB::statement((new InsertSelectCompiler)->compile($step));
        }
    }

    /** @return list<UpgradeStep> in registry order */
    private function steps(): array
    {
        return [new TimelinePostUpgrade, new TimelineReplyUpgrade, new GroupMessageUpgrade, new TimelineReactionUpgrade, new GroupMessageReactionUpgrade];
    }
}
