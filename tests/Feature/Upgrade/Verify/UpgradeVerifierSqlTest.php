<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Models\Member;
use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * Check A (per-step row-count parity) over the three relation steps sharing one member_relationship
 * source. setUp runs the real runner so the target + upgrade-state are populated as production would;
 * each test then either verifies the clean result or corrupts one side.
 */
class UpgradeVerifierSqlTest extends UpgradeSqlTestCase
{
    use SeedsSourceMembers;

    protected function sourceTables(): array
    {
        return ['member', 'member_relationship'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGraph();
        (new UpgradeRunner(new InsertSelectCompiler, $this->relationSteps()))->run(new RunOptions);
    }

    public function test_a_clean_migration_passes(): void
    {
        [$report, $out] = $this->verify();

        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS FriendshipUpgrade', $out);
        $this->assertStringContainsString('PASS MemberBlockUpgrade', $out);
    }

    public function test_a_deleted_target_row_fails(): void
    {
        DB::table('friendships')->limit(1)->delete(); // a target row vanished after the run

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL FriendshipUpgrade', $out);
    }

    public function test_source_drift_fails(): void
    {
        // A new mirrored friend row appears on the source after the run — source now exceeds rows_affected.
        [$a, $b] = $this->activeMembers(2);
        $this->seedRelationship($a, $b, ['is_friend' => 1]);

        [$report] = $this->verify();

        $this->assertTrue($report->failed());
    }

    public function test_a_step_without_a_completed_state_row_fails(): void
    {
        UpgradeState::query()->delete(); // as if the upgrade never ran

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('not completed', $out);
    }

    public function test_a_missing_required_source_is_reported_not_thrown(): void
    {
        // The completed state survives but the required source table is gone: verify must fail on the
        // source preflight, not throw `SELECT COUNT(*) FROM member_relationship` at the missing table.
        DB::statement('DROP TABLE `member_relationship`');

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL source', $out);
    }

    /** @return array{0: VerifyReport, 1: string} */
    public function test_the_json_leads_with_the_site_the_build_and_the_completion_and_checks_the_manifest(): void
    {
        $runner = new UpgradeRunner(new InsertSelectCompiler, $this->relationSteps());
        $this->app->instance(UpgradeVerifier::class, new UpgradeVerifier(new InsertSelectCompiler, $this->relationSteps()));

        $this->assertSame(0, Artisan::call('openpne:verify-upgrade', ['--json' => true, '-q' => true]));
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['site', 'release', 'manifest_hash', 'upgrade_completed_at', 'upgrade_release', 'upgrade_manifest_hash', 'upgrade_verified', 'passed', 'total', 'checks'], array_keys($json));
        $this->assertSame(DB::connection()->getDatabaseName(), $json['site']);
        $this->assertSame($runner->manifest()->hash(), $json['manifest_hash']);
        $this->assertSame($json['manifest_hash'], $json['upgrade_manifest_hash']);
        $this->assertTrue($json['upgrade_verified']);
        $this->assertNotNull($json['upgrade_completed_at']);
        $this->assertSame(['manifest', 'pass'], [end($json['checks'])['name'], end($json['checks'])['status']]);
    }

    public function test_a_marker_from_another_set_of_steps_fails_the_manifest_check(): void
    {
        $marker = UpgradeState::query()->where('step_key', UpgradeRunner::RUN_KEY)->first();
        $marker->update(['metadata' => ['manifest_hash' => '0000'] + $marker->metadata]);

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL manifest: completed under a different set of steps', $out);
    }

    public function test_an_unverified_marker_passes_the_manifest_check_with_the_fact(): void
    {
        $marker = UpgradeState::query()->where('step_key', UpgradeRunner::RUN_KEY)->first();
        $marker->update(['metadata' => ['verified' => false, 'manifest_hash' => null, 'release' => null] + $marker->metadata]);

        [$report, $out] = $this->verify();

        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS manifest: completed under an unknown set of steps', $out);
        $this->assertFalse(json_decode(json_encode($report), true)['upgrade_verified']);
    }

    public function test_without_a_marker_the_manifest_check_fails_and_the_metadata_is_null(): void
    {
        UpgradeState::query()->where('step_key', UpgradeRunner::RUN_KEY)->delete();

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL manifest: no completion marker', $out);
        $this->assertStringContainsString('PASS FriendshipUpgrade', $out, 'the step checks read their own rows');
        $this->assertNull(json_decode(json_encode($report), true)['upgrade_verified']);
    }

    public function test_the_json_write_is_raw(): void
    {
        // A missing source table is reported, not thrown, and its name lands in the check detail.
        $this->app->instance(UpgradeVerifier::class, new UpgradeVerifier(new InsertSelectCompiler, [new FriendshipUpgrade, new class extends MemberBlockUpgrade
        {
            public function sourceTable(): string
            {
                return 'member_<fg=bogus>';
            }
        }]));

        $exit = Artisan::call('openpne:verify-upgrade', ['--json' => true]);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('<fg=bogus>', json_encode($json['checks']));
    }

    private function verify(): array
    {
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, $this->relationSteps()))
            ->verify(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$report, implode("\n", $lines)];
    }

    /** @return list<UpgradeStep> */
    private function relationSteps(): array
    {
        return [new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade];
    }

    private function seedGraph(): void
    {
        [$a, $b, $c, $d, $e, $f] = $this->activeMembers(6);
        $this->seedRelationship($a, $b, ['is_friend' => 1]);
        $this->seedRelationship($b, $a, ['is_friend' => 1]);
        $this->seedRelationship($c, $d, ['is_friend_pre' => 1]);
        $this->seedRelationship($e, $f, ['is_access_block' => 1]);
    }

    /** @param  array<string, int>  $flags */
    private function seedRelationship(Member $from, Member $to, array $flags): void
    {
        DB::table('member_relationship')->insert(array_merge([
            'member_id_from' => $from->id,
            'member_id_to' => $to->id,
            'is_friend' => null,
            'is_friend_pre' => null,
            'is_access_block' => null,
            'created_at' => '2018-01-02 03:04:05',
            'updated_at' => '2018-01-02 03:04:05',
        ], $flags));
    }
}
