<?php

namespace Tests\Feature\Upgrade\SnsSetting;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\SnsSettingUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled sns_config → sns_settings copy against the real OpenPNE 3 `sns_config` DDL:
 * display settings carry over, gadget layout keys are renamed, and the security/unknown keys are not
 * migrated (their values are deferred to the auth-settings work).
 *
 * MySQL only, like the other upgrade SQL tests.
 */
class SnsSettingUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        DB::statement('DROP TABLE IF EXISTS `sns_config`');
        DB::statement(SourceSchema::default()->createStatement('sns_config', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `sns_config`');
        }

        parent::tearDown();
    }

    public function test_migrates_display_settings_verbatim(): void
    {
        $this->seedConfig('sns_name', 'My SNS');
        $this->seedConfig('sns_title', 'Welcome');
        $this->seedConfig('admin_mail_address', 'admin@example.test');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'sns_name', 'value' => 'My SNS']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'sns_title', 'value' => 'Welcome']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'admin_mail_address', 'value' => 'admin@example.test']);
    }

    public function test_migrates_gadget_layout_with_key_remap(): void
    {
        $this->seedConfig('home_layout', 'layoutB');
        $this->seedConfig('profile_layout', 'layoutC');
        $this->seedConfig('login_layout', 'layoutA');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'gadget_home_layout', 'value' => 'layoutB']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'gadget_profile_layout', 'value' => 'layoutC']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'gadget_login_layout', 'value' => 'layoutA']);
    }

    public function test_does_not_migrate_security_or_unknown_keys(): void
    {
        $this->seedConfig('is_use_captcha', '0');   // security key — deferred to the auth-settings work
        $this->seedConfig('enable_pc', '1');         // obsolete in OpenPNE 4
        $this->seedConfig('some_plugin_config', 'x'); // unrecognised custom config

        // sns_settings carries a test baseline (Tests\TestCase seeds the auth keys), so assert the
        // upgrade adds nothing rather than an absolute count.
        $before = DB::table('sns_settings')->count();
        $this->runUpgrade();

        $this->assertSame($before, DB::table('sns_settings')->count());
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new SnsSettingUpgrade));
    }

    private function seedConfig(string $name, string $value): void
    {
        DB::table('sns_config')->insert(['name' => $name, 'value' => $value]);
    }
}
