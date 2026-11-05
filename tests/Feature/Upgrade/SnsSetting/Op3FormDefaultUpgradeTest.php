<?php

namespace Tests\Feature\Upgrade\SnsSetting;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\SnsSettingUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * A site whose admin never saved the SNS settings page has no sns_name row, which OpenPNE 3 showed
 * as its form default "MySNS"; the stamp lands it there instead of this version's app.name.
 */
class Op3FormDefaultUpgradeTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        // The theme pass reads `plugin` whenever sns_config is a source.
        return ['sns_config', 'plugin'];
    }

    public function test_a_source_without_the_row_lands_on_the_openpne3_default(): void
    {
        DB::table('sns_config')->insert(['name' => 'sns_title', 'value' => 'Welcome']);

        $this->assertTrue($this->runner()->run(new RunOptions));

        $this->assertDatabaseHas('sns_settings', ['key' => 'sns_name', 'value' => 'MySNS']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'sns_title', 'value' => 'Welcome']);
    }

    public function test_a_copied_row_stands(): void
    {
        DB::table('sns_config')->insert(['name' => 'sns_name', 'value' => 'Our SNS']);

        $this->assertTrue($this->runner()->run(new RunOptions));

        $this->assertDatabaseHas('sns_settings', ['key' => 'sns_name', 'value' => 'Our SNS']);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'sns_name', 'value' => 'MySNS']);
    }

    public function test_an_operator_set_row_stands_on_a_rerun(): void
    {
        DB::table('sns_settings')->insert(['key' => 'sns_name', 'value' => 'Renamed']);

        $this->assertTrue($this->runner()->run(new RunOptions));

        $this->assertDatabaseHas('sns_settings', ['key' => 'sns_name', 'value' => 'Renamed']);
    }

    public function test_a_dry_run_plans_but_does_not_stamp(): void
    {
        $lines = [];
        $ok = $this->runner()->run(new RunOptions(dryRun: true), function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertTrue($ok);
        $this->assertStringContainsString('sns_name=MySNS', implode("\n", $lines));
        $this->assertDatabaseMissing('sns_settings', ['key' => 'sns_name']);
    }

    private function runner(): UpgradeRunner
    {
        return new UpgradeRunner(new InsertSelectCompiler, [new SnsSettingUpgrade]);
    }
}
