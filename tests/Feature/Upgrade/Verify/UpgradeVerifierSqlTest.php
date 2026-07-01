<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Models\Member;
use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Check A (per-step row-count parity) over the three relation steps sharing one member_relationship
 * source. setUp runs the real runner so the target + upgrade-state are populated as production would;
 * each test then either verifies the clean result or corrupts one side.
 */
class UpgradeVerifierSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('verify re-counts the OpenPNE 3 source DDL on MySQL.');
        }

        DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        DB::statement(SourceSchema::default()->createStatement('member_relationship', withoutForeignKeys: true));
        $this->seedGraph();
        (new UpgradeRunner(new InsertSelectCompiler, $this->relationSteps()))->run(new RunOptions);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        }

        parent::tearDown();
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
        [$a, $b] = Member::factory()->count(2)->create()->all();
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

    /** @return array{0: VerifyReport, 1: string} */
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
        [$a, $b, $c, $d, $e, $f] = Member::factory()->count(6)->create()->all();
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
