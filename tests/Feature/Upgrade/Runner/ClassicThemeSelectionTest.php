<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\Runner\ClassicThemeSelection;
use App\Upgrade\Verify\ClassicThemeCheck;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * The source tables carry what OpenPNE 3 left: a `plugin` row only for a skin someone switched, and
 * a `Theme_used` row only once an administrator saved the theme page.
 */
class ClassicThemeSelectionTest extends UpgradeSqlTestCase
{
    /** @var list<string> */
    private array $out = [];

    protected function sourceTables(): array
    {
        return ['plugin', 'sns_config'];
    }

    public function test_both_skins_enabled_by_absence_is_the_default_skin_and_a_stale_theme_used_is_ignored(): void
    {
        $this->seedConfig('Theme_used', 'cerulean');

        $this->assertTrue($this->runPass());

        $this->assertSame('', $this->stored());
        $this->assertContains('DONE classic_theme_selection: classic_theme = the default skin', $this->out);
    }

    public function test_the_theme_plugin_as_the_one_enabled_skin_carries_theme_used(): void
    {
        $this->seedPlugin('opSkinBasicPlugin', false);
        $this->seedConfig('Theme_used', 'cerulean-dark');

        $this->assertTrue($this->runPass());

        $this->assertSame('cerulean-dark', $this->stored());
        $this->assertContains("DONE classic_theme_selection: classic_theme = 'cerulean-dark'", $this->out);
    }

    public function test_a_missing_theme_used_falls_to_the_first_package_as_openpne3_substituted(): void
    {
        $this->seedPlugin('opSkinBasicPlugin', false);

        $this->assertTrue($this->runPass());
        $this->assertSame('bootstrap', $this->stored());

        UpgradeState::query()->delete();
        $this->seedConfig('Theme_used', '');

        $this->assertTrue($this->runPass());
        $this->assertSame('bootstrap', $this->stored());
    }

    public function test_a_theme_used_not_vendored_here_is_kept_and_reported_and_fails_verify_until_copied_in(): void
    {
        $this->seedPlugin('opSkinBasicPlugin', false);
        $this->seedConfig('Theme_used', 'mysite');

        $this->assertTrue($this->runPass());

        $this->assertSame('mysite', $this->stored());
        $this->assertContains('WARN '.ClassicThemeSelection::missingPackageMessage('mysite'), $this->out);

        $verdicts = [];
        (new ClassicThemeCheck)->verify(['sns_settings'], function (string $name, bool $pass) use (&$verdicts): void {
            $verdicts[] = $pass;
        });
        $this->assertSame([false], $verdicts);
    }

    public function test_a_third_party_skin_enabled_beside_the_theme_plugin_is_the_default_skin_as_openpne3_counted(): void
    {
        $this->seedPlugin('opSkinBasicPlugin', false);
        $this->seedPlugin('opSkinOtherPlugin', true);
        $this->seedConfig('Theme_used', 'cerulean');

        $this->assertTrue($this->runPass());

        $this->assertSame('', $this->stored());
    }

    public function test_a_disabled_theme_plugin_is_the_default_skin_whatever_theme_used_says(): void
    {
        $this->seedPlugin('opSkinThemePlugin', false);
        $this->seedPlugin('opSkinBasicPlugin', false);
        $this->seedConfig('Theme_used', 'cerulean');

        $this->assertTrue($this->runPass());

        $this->assertSame('', $this->stored());
    }

    public function test_skips_unless_the_run_owns_the_settings_table_and_after_a_completed_checkpoint(): void
    {
        $this->seedPlugin('opSkinBasicPlugin', false);

        $this->assertTrue((new ClassicThemeSelection)->run(['members'], '', null, $this->collector()));
        $this->assertNull(DB::table('sns_settings')->where('key', 'classic_theme')->value('value'));

        $this->assertTrue($this->runPass());
        $this->seedConfig('Theme_used', 'winter');
        $this->assertTrue($this->runPass());

        $this->assertContains('SKIP classic_theme_selection: already completed', $this->out);
        $this->assertSame('bootstrap', $this->stored());
    }

    public function test_the_verifier_accepts_the_default_skin_or_a_package_on_disk_and_refuses_the_rest(): void
    {
        $verdicts = [];
        $record = function (string $name, bool $pass, string $detail) use (&$verdicts): void {
            $verdicts[] = [$name, $pass];
        };

        (new ClassicThemeCheck)->verify(['sns_settings'], $record);
        DB::table('sns_settings')->updateOrInsert(['key' => 'classic_theme'], ['value' => 'winter']);
        (new ClassicThemeCheck)->verify(['sns_settings'], $record);
        DB::table('sns_settings')->updateOrInsert(['key' => 'classic_theme'], ['value' => 'gone']);
        (new ClassicThemeCheck)->verify(['sns_settings'], $record);
        (new ClassicThemeCheck)->verify(['members'], $record);

        $this->assertSame([['classic_theme', true], ['classic_theme', true], ['classic_theme', false]], $verdicts);
    }

    private function runPass(): bool
    {
        return (new ClassicThemeSelection)->run(['sns_settings'], '', null, $this->collector());
    }

    private function collector(): \Closure
    {
        return function (string $line): void {
            $this->out[] = $line;
        };
    }

    private function stored(): ?string
    {
        return DB::table('sns_settings')->where('key', 'classic_theme')->value('value');
    }

    private function seedPlugin(string $name, bool $enabled): void
    {
        DB::table('plugin')->updateOrInsert(['name' => $name], ['is_enabled' => $enabled ? 1 : 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedConfig(string $name, string $value): void
    {
        DB::table('sns_config')->updateOrInsert(['name' => $name], ['value' => $value]);
    }
}
