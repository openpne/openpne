<?php

namespace Tests\Feature\Upgrade\Reactions;

use App\Features\Reactions\ReactionVocabulary;
use App\Models\Group;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\NicePreflight;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryCommentReactionUpgrade;
use App\Upgrade\Steps\DiaryReactionUpgrade;
use App\Upgrade\Steps\GroupEventCommentReactionUpgrade;
use App\Upgrade\Steps\GroupMessageReactionUpgrade;
use App\Upgrade\Steps\GroupMessageUpgrade;
use App\Upgrade\Steps\GroupTopicCommentReactionUpgrade;
use App\Upgrade\Steps\NiceReactionUpgrade;
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

/** The opLikePlugin like, following its activity's landing or its record's existence; MySQL only. */
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
        foreach (NiceReactionUpgrade::RECORD_TABLES as $table) {
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
        Group::factory()->create(['id' => 5]);
        $this->seedSourceCommunity(5);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (NiceReactionUpgrade::RECORD_TABLES as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
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
        $this->assertContains('WARN '.NicePreflight::goneTargetLikeMessage('diaries', 1, [4]), $lines);
        $this->assertContains('WARN '.NicePreflight::goneTargetLikeMessage('event comments', 1, [5]), $lines);
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
        $this->seedDiary(1, $member->id);
        $this->seedNice(6, $member->id, 'D', 1);
        $this->seedNice(7, $member->id, 'D', 1);
        $this->seedNice(8, $member->id, 'D', 404); // no diary: its twins land nowhere, so no abort
        $this->seedNice(9, $member->id, 'D', 404);

        $lines = [];
        $ran = (new UpgradeRunner(new InsertSelectCompiler, $this->steps()))->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertFalse($ran);
        $this->assertContains('ERROR '.NicePreflight::duplicateLikeMessage(2, [2, 7]), $lines);
        $this->assertContains('WARN '.NicePreflight::unknownTableLikeMessage('x', 1, [5]), $lines);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_like_on_a_record_lands_on_it_under_the_records_alias(): void
    {
        [$author, $fan] = $this->activeMembers(2);
        $this->seedDiary(1, $author->id);
        $this->seedRecord('diary_comment', 2, ['diary_id' => 1, 'member_id' => $author->id]);
        $this->seedRecord('community_topic_comment', 3, ['community_topic_id' => 1, 'member_id' => $author->id]);
        $this->seedRecord('community_event_comment', 4, ['community_event_id' => 1, 'member_id' => $author->id]);
        $this->seedNice(1, $fan->id, 'D', 1);
        $this->seedNice(2, $fan->id, 'd', 2);
        $this->seedNice(3, $fan->id, 't', 3);
        $this->seedNice(4, $fan->id, 'e', 4);
        $this->seedNice(5, $fan->id, 'A', 1); // no such activity

        $this->runSteps();

        $this->assertSame(
            [[1, 'diary', 1], [2, 'diaryComment', 2], [3, 'groupTopicComment', 3], [4, 'groupEventComment', 4]],
            DB::table('reactions')->orderBy('id')->get()->map(fn (object $r): array => [(int) $r->id, $r->reactable_type, (int) $r->reactable_id])->all(),
        );
    }

    /** The letter names a record that is gone: nothing to land on, and the drop is counted per kind. */
    public function test_a_like_on_a_record_that_no_longer_exists_is_left_behind_and_counted(): void
    {
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id);
        $this->seedNice(1, $member->id, 'D', 1);
        $this->seedNice(2, $member->id, 'd', 1);
        $this->seedNice(3, $member->id, 't', 1);
        $this->seedNice(4, $member->id, 'e', 1);

        $lines = [];
        $ran = (new UpgradeRunner(new InsertSelectCompiler, $this->steps()))->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertTrue($ran, implode("\n", $lines));
        $this->assertDatabaseCount('reactions', 0);
        foreach ([['diaries', 1], ['diary comments', 2], ['topic comments', 3], ['event comments', 4]] as [$what, $id]) {
            $this->assertContains('WARN '.NicePreflight::goneTargetLikeMessage($what, 1, [$id]), $lines);
        }
    }

    /** A source with opLikePlugin but not the liked record's plugin: the like is counted without touching the table it lacks. */
    public function test_a_like_on_a_record_whose_plugin_is_absent_is_counted_without_its_table(): void
    {
        $member = $this->activeMember();
        $this->seedNice(1, $member->id, 'D', 1);
        $this->seedNice(2, $member->id, 't', 1);
        DB::statement('DROP TABLE `diary`');

        $report = (new NicePreflight)->inspect('', null, ['nice', 'activity_data', 'community', 'community_topic_comment']);

        $this->assertSame([], $report->errors);
        $this->assertSame([
            NicePreflight::uninstalledTargetLikeMessage('diaries', 1, [1]),
            NicePreflight::goneTargetLikeMessage('topic comments', 1, [2]),
        ], $report->warnings);
    }

    /** The stock DDL is byte-collated and would hide a comparison that is not. */
    public function test_a_letter_is_its_bytes_whatever_the_sources_collation(): void
    {
        $this->createCaseInsensitiveSourceNiceTable();
        $member = $this->activeMember();
        $this->seedActivity(1, $member->id);
        // Distinct targets: the folding unique index would refuse `D` and `d` on one id.
        $this->seedNice(1, $member->id, 'a', 1); // not an activity like, whatever the collation says
        $this->seedNice(2, $member->id, 'D', 2);
        $this->seedNice(3, $member->id, 'd', 3);
        $this->seedNice(4, $member->id, 'X', 1); // two unknown letters the collation folds into one
        $this->seedNice(5, $member->id, 'x', 2);

        $lines = [];
        (new UpgradeRunner(new InsertSelectCompiler, $this->steps()))->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertDatabaseCount('reactions', 0);
        $this->assertContains('WARN '.NicePreflight::goneTargetLikeMessage('diaries', 1, [2]), $lines);
        $this->assertContains('WARN '.NicePreflight::goneTargetLikeMessage('diary comments', 1, [3]), $lines);
        $this->assertContains('WARN '.NicePreflight::unknownTableLikeMessage('a', 1, [1]), $lines);
        $this->assertContains('WARN '.NicePreflight::unknownTableLikeMessage('X', 1, [4]), $lines);
        $this->assertContains('WARN '.NicePreflight::unknownTableLikeMessage('x', 1, [5]), $lines);
    }

    /** A pre-index source with a folding collation: one member's likes on diary 1 and diary comment 1 are two targets, not a doubled like. */
    public function test_a_diary_and_a_comment_like_on_one_id_are_not_twins_under_a_folding_collation(): void
    {
        $this->createCaseInsensitiveSourceNiceTable();
        DB::statement('ALTER TABLE `nice` DROP INDEX `member_id_foreign_table_foreign_id_UNIQUE_idx`');
        $member = $this->activeMember();
        $this->seedDiary(1, $member->id);
        $this->seedRecord('diary_comment', 1, ['diary_id' => 1, 'member_id' => $member->id]);
        $this->seedNice(1, $member->id, 'D', 1);
        $this->seedNice(2, $member->id, 'd', 1);

        $lines = [];
        $ran = (new UpgradeRunner(new InsertSelectCompiler, $this->steps()))->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertTrue($ran, implode("\n", $lines));
        $this->assertSame(
            [[1, 'diary', 1], [2, 'diaryComment', 1]],
            DB::table('reactions')->orderBy('id')->get()->map(fn (object $r): array => [(int) $r->id, $r->reactable_type, (int) $r->reactable_id])->all(),
        );
    }

    public function test_verify_agrees_on_every_target(): void
    {
        [$author, $fan] = $this->activeMembers(2);
        $this->seedActivity(1, $author->id);
        $this->seedActivity(2, $author->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedNice(1, $fan->id, 'A', 1);
        $this->seedNice(2, $fan->id, 'A', 2);
        $this->seedNice(3, $author->id, 'A', 2);
        $this->seedDiary(1, $author->id);
        $this->seedRecord('diary_comment', 2, ['diary_id' => 1, 'member_id' => $author->id]);
        $this->seedRecord('community_topic_comment', 3, ['community_topic_id' => 1, 'member_id' => $author->id]);
        $this->seedRecord('community_event_comment', 4, ['community_event_id' => 1, 'member_id' => $author->id]);
        $this->seedNice(4, $fan->id, 'D', 1);
        $this->seedNice(5, $fan->id, 'D', 2); // no diary 2
        $this->seedNice(6, $fan->id, 'd', 2);
        $this->seedNice(7, $fan->id, 't', 3);
        $this->seedNice(8, $fan->id, 'e', 4);

        $steps = $this->steps();
        (new UpgradeRunner(new InsertSelectCompiler, $steps))->run(new RunOptions);
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, $steps))->verify(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $out = implode("\n", $lines);
        $this->assertFalse($report->failed(), $out);
        foreach (['TimelineReactionUpgrade', 'GroupMessageReactionUpgrade', 'DiaryReactionUpgrade', 'DiaryCommentReactionUpgrade', 'GroupTopicCommentReactionUpgrade', 'GroupEventCommentReactionUpgrade'] as $step) {
            $this->assertStringContainsString("PASS {$step}", $out);
        }
        $this->assertDatabaseCount('reactions', 7);
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
        return [
            new TimelinePostUpgrade, new TimelineReplyUpgrade, new GroupMessageUpgrade,
            new TimelineReactionUpgrade, new GroupMessageReactionUpgrade,
            new DiaryReactionUpgrade, new DiaryCommentReactionUpgrade, new GroupTopicCommentReactionUpgrade, new GroupEventCommentReactionUpgrade,
        ];
    }

    private function seedDiary(int $id, int $memberId): void
    {
        DB::table('diary')->insert(['id' => $id, 'member_id' => $memberId, 'title' => 'T', 'body' => 'B', 'public_flag' => 1, 'is_open' => 0, 'has_images' => 0,
            'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00']);
    }

    /** @param  array<string, mixed>  $columns */
    private function seedRecord(string $table, int $id, array $columns): void
    {
        DB::table($table)->insert(['id' => $id, 'number' => 1, 'body' => 'B', 'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00'] + $columns
            + ($table === 'diary_comment' ? ['has_images' => 0] : []));
    }
}
