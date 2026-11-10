<?php

namespace Tests\Feature\Upgrade\SnsSetting;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * The Classic-default surface stamp is a runner write (not the command's), so a direct
 * UpgradeRunner invocation gets it too; pinned as insert-if-absent, after a full success only,
 * never on a dry run. The relation source and steps are the smallest walk that reaches the end,
 * since the stamp fires on any full success.
 */
class SurfaceModeUpgradeTest extends UpgradeSqlTestCase
{
    use SeedsSourceMembers;

    protected function sourceTables(): array
    {
        return ['member', 'member_relationship'];
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

    public function test_a_full_run_stamps_no_tab_icon_even_when_the_surface_was_set_first(): void
    {
        // The surface stamp returns false here; the favicon stamp must not hang off it.
        DB::table('sns_settings')->insert(['key' => 'surface_mode', 'value' => 'modern_only']);
        $this->seedGraph();

        $lines = [];
        $this->assertTrue($this->runner()->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $this->assertDatabaseHas('sns_settings', ['key' => 'brand_favicon_fallback', 'value' => 'none']);
        $this->assertStringContainsString('No browser-tab icon', implode("\n", $lines));
    }

    public function test_a_full_run_stamps_the_digest_as_the_modern_home_even_when_the_surface_was_set_first(): void
    {
        DB::table('sns_settings')->insert(['key' => 'surface_mode', 'value' => 'modern_only']);
        $this->seedGraph();

        $lines = [];
        $this->assertTrue($this->runner()->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $this->assertDatabaseHas('sns_settings', ['key' => 'modern_home', 'value' => 'whats_new']);
        $this->assertStringContainsString("What's new lists", implode("\n", $lines));
    }

    public function test_the_modern_home_stamp_is_insert_if_absent(): void
    {
        DB::table('sns_settings')->insert(['key' => 'modern_home', 'value' => 'issue']);
        $this->seedGraph();

        $lines = [];
        $this->assertTrue($this->runner()->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $this->assertDatabaseHas('sns_settings', ['key' => 'modern_home', 'value' => 'issue']);
        $this->assertStringNotContainsString("What's new lists", implode("\n", $lines));
    }

    public function test_the_tab_icon_stamp_is_insert_if_absent(): void
    {
        DB::table('sns_settings')->insert(['key' => 'brand_favicon_fallback', 'value' => 'generated']);
        $this->seedGraph();

        $lines = [];
        $this->assertTrue($this->runner()->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $this->assertDatabaseHas('sns_settings', ['key' => 'brand_favicon_fallback', 'value' => 'generated']);
        $this->assertStringNotContainsString('No browser-tab icon', implode("\n", $lines));
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
        $this->assertStringContainsString('brand_favicon_fallback=none', implode("\n", $lines));
        $this->assertStringContainsString('modern_home=whats_new', implode("\n", $lines));
        $this->assertDatabaseMissing('sns_settings', ['key' => 'modern_home']);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'surface_mode']);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'brand_favicon_fallback']);
    }

    private function runner(): UpgradeRunner
    {
        return new UpgradeRunner(new InsertSelectCompiler, [new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade]);
    }

    private function seedGraph(): void
    {
        [$a, $b] = $this->activeMembers(2);

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
