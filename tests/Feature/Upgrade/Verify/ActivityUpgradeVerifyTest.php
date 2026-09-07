<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Models\TimelinePost;
use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\TimelinePostUpgrade;
use App\Upgrade\Steps\TimelineReplyUpgrade;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/**
 * Check A over `timeline_posts`, which two steps share, plus the template check: a run through the
 * runner (steps, then the template pass) verifies clean, and each way the target can drift fails.
 * MySQL only.
 */
class ActivityUpgradeVerifyTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities, SeedsSourceMembers;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('verify re-counts the OpenPNE 3 source DDL on MySQL.');
        }

        $this->createSourceMemberTable();
        $this->createSourceActivityTables();
        config(['openpne.site_locale' => 'en']);
        URL::forceRootUrl('http://sns.example');

        $member = $this->activeMember();
        $this->seedActivity(1, $member->id);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1]);
        $this->seedActivity(3, $member->id, ['body' => '[Diary] a'] + $this->templateRow('diary', ['%1%' => 'a'], '@diary_show?id=1'));
        $this->seedActivity(4, $member->id, ['body' => 'kept [i:1]'] + $this->templateRow('friend_link', ['%1%' => 'a'], '@diary_show?id=1'));
        $this->assertTrue($this->runner()->run(new RunOptions));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceActivityTables();
            $this->dropSourceMemberTable();
        }

        parent::tearDown();
    }

    public function test_a_clean_run_passes_both_steps_and_the_template_check(): void
    {
        [$report, $out] = $this->verify();

        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS TimelinePostUpgrade: 3 rows', $out);
        $this->assertStringContainsString('PASS TimelineReplyUpgrade: 1 rows', $out);
        $this->assertStringContainsString('PASS activity_template:timeline_posts: 2 template rows hold their rendered body', $out);
    }

    public function test_a_row_in_the_wrong_step_reads_as_drift_for_both(): void
    {
        TimelinePost::query()->whereKey(2)->update(['in_reply_to_id' => null]);

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL TimelinePostUpgrade: target mismatch', $out);
        $this->assertStringContainsString('FAIL TimelineReplyUpgrade: target mismatch', $out);
    }

    public function test_a_template_body_that_is_not_the_render_and_a_missing_pass_checkpoint_fail(): void
    {
        TimelinePost::query()->whereKey(3)->update(['body' => "[Diary] a\nhttp://sns.example/diary/9"]);
        [$report, $out] = $this->verify();
        $this->assertStringContainsString('FAIL activity_template:timeline_posts: 1 template row(s) differ from the render (e.g. ids 3)', $out);

        TimelinePost::query()->whereKey(3)->update(['body' => "[Diary] a\nhttp://sns.example/diary/1"]);
        TimelinePost::query()->whereKey(4)->update(['body' => 'kept [i:1]']); // the emoji pass's work undone
        [$report, $out] = $this->verify();
        $this->assertStringContainsString('FAIL activity_template:timeline_posts: 1 template row(s) differ from the render (e.g. ids 4)', $out);

        UpgradeState::query()->where('step_key', 'activity_template_timeline_posts')->delete();
        [$report, $out] = $this->verify();
        $this->assertStringContainsString('FAIL activity_template:timeline_posts: not completed', $out);
    }

    private function runner(): UpgradeRunner
    {
        return new UpgradeRunner(new InsertSelectCompiler, [new TimelinePostUpgrade, new TimelineReplyUpgrade]);
    }

    /** @return array{VerifyReport, string} */
    private function verify(): array
    {
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, [new TimelinePostUpgrade, new TimelineReplyUpgrade]))
            ->verify(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$report, implode("\n", $lines)];
    }
}
