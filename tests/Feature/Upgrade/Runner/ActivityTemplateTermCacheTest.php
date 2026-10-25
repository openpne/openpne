<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\TimelinePost;
use App\Services\TermService;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\TermOverrideUpgrade;
use App\Upgrade\Steps\TimelinePostUpgrade;
use App\Upgrade\Steps\TimelineReplyUpgrade;
use App\Upgrade\Verify\UpgradeVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class ActivityTemplateTermCacheTest extends UpgradeSqlTestCase
{
    use SeedsSourceActivities, SeedsSourceMembers;

    protected function sourceTables(): array
    {
        return ['member', 'sns_term', 'sns_term_translation', ...self::ACTIVITY_SOURCE_TABLES];
    }

    /** The term map is read before the walk, as the mail preflight does, and the site renames `community`. */
    public function test_the_template_pass_renders_with_the_terms_the_walk_migrated(): void
    {
        config(['openpne.site_locale' => 'en']);
        URL::forceRootUrl('http://sns.example');
        $id = (int) DB::table('sns_term')->insertGetId(['name' => 'community', 'application' => 'pc_frontend']);
        DB::table('sns_term_translation')->insert(['id' => $id, 'lang' => 'en', 'value' => 'Circle']);
        $this->seedActivity(1, $this->activeMember()->id, ['body' => '[Community Topic] t']
            + $this->templateRow('community_topic', ['%1%' => 'Runners', '%2%' => 'Marathon'], '@communityTopic_show?id=7'));
        $this->assertSame('group', app(TermService::class)->getTerms('en')['community']);

        $steps = [new TermOverrideUpgrade, new TimelinePostUpgrade, new TimelineReplyUpgrade];
        $this->assertTrue((new UpgradeRunner(new InsertSelectCompiler, $steps))->run(new RunOptions));

        $this->assertSame("[Circle topic] Marathon (Runners)\nhttp://sns.example/topics/7", TimelinePost::find(1)->body);
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, $steps))->verify(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertFalse($report->failed(), implode("\n", $lines));
    }
}
