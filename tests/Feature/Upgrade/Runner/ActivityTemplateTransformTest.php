<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\UpgradeState;
use App\Upgrade\Runner\ActivityTemplateRenderer;
use App\Upgrade\Runner\ActivityTemplateTransform;
use App\Upgrade\Runner\EmojiMap;
use App\Upgrade\Runner\EmojiTransform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\TestCase;

/** The post-walk template pass over both landing tables; MySQL only (the source DDL). */
class ActivityTemplateTransformTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The pass reads the OpenPNE 3 source DDL on MySQL.');
        }

        $this->createSourceActivityTables();
        config(['openpne.site_locale' => 'en']);
        URL::forceRootUrl('http://sns.example');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceActivityTables();
        }

        parent::tearDown();
    }

    public function test_renders_the_template_rows_in_both_tables_and_keeps_the_rest_with_a_count(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create();
        $this->seedActivity(1, $member->id, ['body' => '[Diary] a'] + $this->templateRow('diary', ['%1%' => 'a'], '@diary_show?id=1'));
        $this->seedActivity(2, $member->id, ['body' => 'plain']);
        $this->seedActivity(3, $member->id, ['body' => 'kept'] + $this->templateRow('friend_link', ['%1%' => 'x'], '@diary_show?id=1'));
        $this->seedActivity(4, $member->id, ['body' => 'kept too'] + $this->templateRow('diary', ['%1%' => 'x'], '@member_profile?id=1'));
        $this->seedActivity(5, $member->id, ['body' => '[Community Topic] t', 'foreign_table' => 'community', 'foreign_id' => $group->id]
            + $this->templateRow('community_topic', ['%1%' => 'Runners', '%2%' => 'Marathon'], '@communityTopic_show?id=7'));
        foreach ([1, 2, 3, 4] as $id) {
            TimelinePost::factory()->create(['id' => $id, 'member_id' => $member->id, 'body' => DB::table('activity_data')->where('id', $id)->value('body'), 'updated_at' => '2015-05-06 07:08:09']);
        }
        GroupMessage::factory()->create(['id' => 5, 'group_id' => $group->id, 'member_id' => $member->id, 'body' => '[Community Topic] t']);

        $lines = $this->runPass(['timeline_posts', 'group_messages']);

        $this->assertSame("[Diary] a\nhttp://sns.example/diary/1", TimelinePost::find(1)->body);
        $this->assertSame('2015-05-06 07:08:09', TimelinePost::find(1)->updated_at->format('Y-m-d H:i:s')); // a rewrite is not an edit
        $this->assertSame('plain', TimelinePost::find(2)->body);
        $this->assertSame('kept', TimelinePost::find(3)->body);
        $this->assertSame('kept too', TimelinePost::find(4)->body);
        $this->assertSame("[Group topic] Marathon (Runners)\nhttp://sns.example/topics/7", GroupMessage::find(5)->body);
        $this->assertContains('DONE activity_template_timeline_posts: 1 rows', $lines);
        $this->assertContains('WARN '.ActivityTemplateTransform::keptMessage('timeline_posts', ActivityTemplateRenderer::UNKNOWN_TEMPLATE, 1), $lines);
        $this->assertContains('WARN '.ActivityTemplateTransform::keptMessage('timeline_posts', ActivityTemplateRenderer::NO_LINK, 1), $lines);
        $this->assertContains('DONE activity_template_group_messages: 1 rows', $lines);
        $this->assertEquals(['last_id' => 4, 'kept' => [ActivityTemplateRenderer::UNKNOWN_TEMPLATE => 1, ActivityTemplateRenderer::NO_LINK => 1], 'rendered' => 1, 'locale' => 'en', 'root_url' => 'http://sns.example'],
            UpgradeState::query()->where('step_key', 'activity_template_timeline_posts')->value('metadata'));
    }

    public function test_the_rendered_body_is_final_whichever_pass_order_or_restart_runs(): void
    {
        $member = Member::factory()->create();
        $this->seedActivity(1, $member->id, ['body' => '[Diary] [i:1]'] + $this->templateRow('diary', ['%1%' => 'sun [i:1]'], '@diary_show?id=1'));
        TimelinePost::factory()->create(['id' => 1, 'member_id' => $member->id, 'body' => '[Diary] [i:1]']);

        $this->runPass(['timeline_posts']);
        $expected = '[Diary] sun '.EmojiMap::convert('[i:1]')."\nhttp://sns.example/diary/1";
        $this->assertSame($expected, TimelinePost::find(1)->body);

        // The emoji pass finds nothing left to convert, and a restart of the template pass from a
        // cleared checkpoint lands on the same text.
        $this->assertTrue((new EmojiTransform)->run(['timeline_posts'], static fn (string $l): null => null));
        $this->assertSame($expected, TimelinePost::find(1)->body);
        UpgradeState::query()->where('step_key', 'activity_template_timeline_posts')->delete();
        $this->runPass(['timeline_posts']);
        $this->assertSame($expected, TimelinePost::find(1)->body);
    }

    public function test_a_persisted_cursor_resumes_without_recounting_and_a_completed_pass_is_skipped(): void
    {
        $member = Member::factory()->create();
        foreach ([1, 2] as $id) {
            $this->seedActivity($id, $member->id, ['body' => 'kept'] + $this->templateRow('friend_link', ['%1%' => 'x'], '@diary_show?id=1'));
            TimelinePost::factory()->create(['id' => $id, 'member_id' => $member->id, 'body' => 'kept']);
        }
        // A crash after chunk 1 committed: its count and cursor were committed with it.
        UpgradeState::create(['step_key' => 'activity_template_timeline_posts', 'status' => UpgradeState::STATUS_FAILED,
            'metadata' => ['last_id' => 1, 'kept' => [ActivityTemplateRenderer::UNKNOWN_TEMPLATE => 1], 'rendered' => 0]]);

        $lines = $this->runPass(['timeline_posts']);

        $this->assertContains('WARN '.ActivityTemplateTransform::keptMessage('timeline_posts', ActivityTemplateRenderer::UNKNOWN_TEMPLATE, 2), $lines);
        $this->assertContains('SKIP activity_template_timeline_posts: already completed', $this->runPass(['timeline_posts']));
    }

    public function test_a_resume_renders_under_the_locale_and_url_the_first_run_recorded(): void
    {
        $member = Member::factory()->create();
        foreach ([1, 2] as $id) {
            $this->seedActivity($id, $member->id, ['body' => '[Diary] a'] + $this->templateRow('diary', ['%1%' => 'a'], "@diary_show?id={$id}"));
            TimelinePost::factory()->create(['id' => $id, 'member_id' => $member->id, 'body' => '[Diary] a']);
        }
        // Chunk 1 rendered under (en, http://sns.example); the operator then changed the site before resuming.
        UpgradeState::create(['step_key' => 'activity_template_timeline_posts', 'status' => UpgradeState::STATUS_FAILED,
            'metadata' => ['last_id' => 1, 'kept' => [], 'rendered' => 1, 'locale' => 'en', 'root_url' => 'http://sns.example']]);
        config(['openpne.site_locale' => 'ja']);
        URL::forceRootUrl('https://moved.example');
        URL::forceScheme('https');

        $this->runPass(['timeline_posts']);

        $this->assertSame("[Diary] a\nhttp://sns.example/diary/2", TimelinePost::find(2)->body);
        $this->assertSame('https://moved.example', URL::to('/')); // the live generator is handed back as it was
    }

    public function test_only_tables_owned_by_the_run_are_touched_and_plan_writes_nothing(): void
    {
        $member = Member::factory()->create();
        $this->seedActivity(1, $member->id, ['body' => 'raw'] + $this->templateRow('diary', ['%1%' => 'a'], '@diary_show?id=1'));
        TimelinePost::factory()->create(['id' => 1, 'member_id' => $member->id, 'body' => 'raw']);

        $lines = [];
        (new ActivityTemplateTransform)->plan(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertStringStartsWith('PLAN ', $lines[0]);
        $this->runPass(['members']);

        $this->assertSame('raw', TimelinePost::find(1)->body);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function runPass(array $tables): array
    {
        $lines = [];
        $ok = (new ActivityTemplateTransform)->run($tables, '', null, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertTrue($ok, implode("\n", $lines));

        return $lines;
    }
}
