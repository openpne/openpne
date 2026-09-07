<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Features\GroupTalk\Queries\UnreadTalkCounts;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\GroupMemberUpgrade;
use App\Upgrade\Steps\GroupMessageUpgrade;
use App\Upgrade\Steps\TimelinePostUpgrade;
use App\Upgrade\Steps\TimelineReplyUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/**
 * The activity steps and the post-walk passes through the runner; MySQL only. A source row dated
 * after the run is what separates the cursor backfill from the schema default it replaces.
 */
class ActivityUpgradeRunnerSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities, SeedsSourceMembers;

    private const MEMBERSHIP_TABLES = ['community_member', 'community_member_position'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The runner reads the OpenPNE 3 source DDL on MySQL.');
        }

        $this->createSourceMemberTable();
        $this->createSourceActivityTables();
        foreach (self::MEMBERSHIP_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
        config(['openpne.site_locale' => 'en']);
        URL::forceRootUrl('http://sns.example');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (array_reverse(self::MEMBERSHIP_TABLES) as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
            $this->dropSourceActivityTables();
            $this->dropSourceMemberTable();
        }

        parent::tearDown();
    }

    public function test_the_walk_renders_then_converts_emoji_then_points_the_cursors_at_the_history(): void
    {
        [$author, $reader] = $this->activeMembers(2);
        Group::factory()->create(['id' => 5]);
        $this->seedSourceCommunity(5);
        foreach ([1 => $author, 2 => $reader] as $id => $member) {
            DB::table('community_member')->insert(['id' => $id, 'community_id' => 5, 'member_id' => $member->id, 'is_pre' => 0, 'created_at' => '2015-01-01 00:00:00', 'updated_at' => '2015-01-01 00:00:00']);
        }
        $tomorrow = now()->addDay()->format('Y-m-d H:i:s');
        $this->seedActivity(1, $author->id, ['foreign_table' => 'community', 'foreign_id' => 5, 'body' => '[Community Topic] stored', 'created_at' => $tomorrow, 'updated_at' => $tomorrow]
            + $this->templateRow('community_topic', ['%1%' => 'Runners [i:1]', '%2%' => 'Marathon'], '@communityTopic_show?id=7'));
        $this->seedActivity(2, $reader->id, ['in_reply_to_activity_id' => 1, 'foreign_table' => 'community', 'foreign_id' => 5, 'body' => 'reply [i:1]']);
        $this->seedActivity(3, $author->id, ['body' => 'hello [i:1]']);

        $plan = $this->runUpgrade(new RunOptions(dryRun: true));

        $this->assertContains("PLAN would point every migrated group membership's talk read cursor at the group's latest migrated message.", $plan);
        $this->assertSame(0, DB::table('group_messages')->count() + DB::table('group_members')->count() + DB::table('timeline_posts')->count());

        $lines = $this->runUpgrade(new RunOptions);

        $this->assertContains('DONE talk_read_cursor_backfill: 2 memberships', $lines);
        $this->assertDatabaseHas('group_messages', ['id' => 1, 'group_id' => 5, 'in_reply_to_id' => null, 'body' => "[Group topic] Marathon (Runners \u{2600}\u{FE0F})\nhttp://sns.example/topics/7"]);
        $this->assertDatabaseHas('group_messages', ['id' => 2, 'group_id' => 5, 'in_reply_to_id' => 1, 'body' => "reply \u{2600}\u{FE0F}"]);
        $this->assertDatabaseHas('timeline_posts', ['id' => 3, 'body' => "hello \u{2600}\u{FE0F}"]);
        // The template row is the group's latest message and is dated after the run: without the
        // backfill the reader's default cursor would leave it unread.
        $this->assertSame(0, app(UnreadTalkCounts::class)($reader)[5]['count']);
        $this->assertDatabaseHas('group_members', ['group_id' => 5, 'member_id' => $reader->id, 'talk_read_at' => $tomorrow, 'talk_read_message_id' => 1]);

        $this->assertStringContainsString("PASS talk_read_cursor: no membership is behind its group's latest migrated message", $this->verify());

        // Talk written after the upgrade is the site's own: a member yet to read it is not drift.
        GroupMessage::factory()->create(['id' => 100, 'group_id' => 5, 'member_id' => $author->id, 'created_at' => now()->addDays(2), 'updated_at' => now()->addDays(2)]);
        $this->assertStringContainsString('PASS talk_read_cursor', $this->verify());

        DB::table('group_members')->where('member_id', $reader->id)->update(['talk_read_message_id' => 0]);
        $this->assertStringContainsString("FAIL talk_read_cursor: 1 membership(s) are behind their group's latest migrated message (e.g. group:member 5:{$reader->id})", $this->verify());

        DB::table('group_members')->where('member_id', $reader->id)->update(['talk_read_message_id' => 1]);
        UpgradeState::query()->where('step_key', 'talk_read_cursor_backfill')->delete();
        $this->assertStringContainsString('FAIL talk_read_cursor: not completed', $this->verify());
    }

    private function verify(): string
    {
        $lines = [];
        (new UpgradeVerifier(new InsertSelectCompiler, $this->steps()))->verify(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return implode("\n", $lines);
    }

    /** @return list<UpgradeStep> */
    private function steps(): array
    {
        return [new GroupMemberUpgrade, new TimelinePostUpgrade, new TimelineReplyUpgrade, new GroupMessageUpgrade];
    }

    /** @return list<string> */
    private function runUpgrade(RunOptions $options): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, $this->steps()))->run($options, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertTrue($ok, implode("\n", $lines));

        return $lines;
    }
}
