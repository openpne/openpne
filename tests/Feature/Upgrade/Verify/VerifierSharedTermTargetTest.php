<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\TermOverrideUpgrade;
use App\Upgrade\Steps\WithdrawnMemberTermEnUpgrade;
use App\Upgrade\Steps\WithdrawnMemberTermJaUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/** Check A over `term_overrides`, which the sns_term step and the withdrawn-member steps share. */
class VerifierSharedTermTargetTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['sns_term', 'sns_term_translation', 'sns_config', 'plugin'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $id = (int) DB::table('sns_term')->insertGetId(['name' => 'community', 'application' => 'pc_frontend']);
        DB::table('sns_term_translation')->insert(['id' => $id, 'lang' => 'ja_JP', 'value' => 'コミュニティ']);
        DB::table('sns_config')->insert(['name' => 'nickname_of_member_who_does_not_have_credentials', 'value' => '卒業生']);
        $this->assertTrue((new UpgradeRunner(new InsertSelectCompiler, $this->termSteps()))->run(new RunOptions));
    }

    public function test_each_step_counts_only_its_own_rows(): void
    {
        $this->assertSame(3, DB::table('term_overrides')->count());

        [$report, $out] = $this->verify();

        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS TermOverrideUpgrade', $out);
        $this->assertStringContainsString('PASS WithdrawnMemberTermJaUpgrade', $out);
        $this->assertStringContainsString('PASS WithdrawnMemberTermEnUpgrade', $out);
    }

    public function test_scoping_still_catches_a_lost_row(): void
    {
        DB::table('term_overrides')->where('name', 'withdrawn_member')->where('locale', 'en')->delete();

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL WithdrawnMemberTermEnUpgrade', $out);
        $this->assertStringContainsString('PASS WithdrawnMemberTermJaUpgrade', $out);
        $this->assertStringContainsString('PASS TermOverrideUpgrade', $out);
    }

    /** @return array{0: VerifyReport, 1: string} */
    private function verify(): array
    {
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, $this->termSteps()))
            ->verify(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$report, implode("\n", $lines)];
    }

    /** @return list<UpgradeStep> */
    private function termSteps(): array
    {
        return [new TermOverrideUpgrade, new WithdrawnMemberTermJaUpgrade, new WithdrawnMemberTermEnUpgrade];
    }
}
