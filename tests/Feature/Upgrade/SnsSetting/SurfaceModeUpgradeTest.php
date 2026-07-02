<?php

namespace Tests\Feature\Upgrade\SnsSetting;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The upgrade establishes the Classic-default surface for a migrated site — a fail-safe write in the
 * runner (not the command) so a direct UpgradeRunner invocation gets it too. Insert-if-absent, only
 * after a full success, and never on a dry-run. MySQL-only, like the runner (the relation source +
 * steps mirror UpgradeRunnerSqlTest; the stamp fires on any full walk, independent of file migration).
 */
class SurfaceModeUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The runner executes INSERT...SELECT on MySQL.');
        }

        DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        DB::statement(SourceSchema::default()->createStatement('member_relationship', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        }

        parent::tearDown();
    }

    public function test_a_full_run_stamps_classic_default(): void
    {
        $this->seedGraph();

        $this->assertTrue($this->runner()->run(new RunOptions));

        $this->assertDatabaseHas('sns_settings', ['key' => 'surface_mode', 'value' => 'classic_default']);
    }

    public function test_the_stamp_is_insert_if_absent(): void
    {
        // An operator already switched to modern_only; a (re-)run must not clobber it.
        DB::table('sns_settings')->insert(['key' => 'surface_mode', 'value' => 'modern_only']);
        $this->seedGraph();

        $this->assertTrue($this->runner()->run(new RunOptions));

        $this->assertDatabaseHas('sns_settings', ['key' => 'surface_mode', 'value' => 'modern_only']);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'surface_mode', 'value' => 'classic_default']);
    }

    public function test_a_dry_run_does_not_stamp(): void
    {
        $this->seedGraph();

        $lines = [];
        $ok = $this->runner()->run(new RunOptions(dryRun: true), function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertTrue($ok);
        $this->assertStringContainsString('surface_mode=classic_default', implode("\n", $lines));
        $this->assertDatabaseMissing('sns_settings', ['key' => 'surface_mode']);
    }

    private function runner(): UpgradeRunner
    {
        return new UpgradeRunner(new InsertSelectCompiler, [new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade]);
    }

    private function seedGraph(): void
    {
        [$a, $b] = Member::factory()->count(2)->create()->all();

        foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
            DB::table('member_relationship')->insert([
                'member_id_from' => $from->id,
                'member_id_to' => $to->id,
                'is_friend' => 1,
                'is_friend_pre' => null,
                'is_access_block' => null,
                'created_at' => '2018-01-02 03:04:05',
                'updated_at' => '2018-01-02 03:04:05',
            ]);
        }
    }
}
