<?php

namespace Tests\Feature\Upgrade\SnsSetting;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\SnsSettingUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled sns_config → sns_settings copy against the real OpenPNE 3 `sns_config` DDL:
 * display settings carry over, gadget layout keys are renamed, and the security/unknown keys are not
 * migrated (a security key's OpenPNE 3 value must not silently override its fail-closed default).
 *
 * MySQL only, like the other upgrade SQL tests.
 */
class SnsSettingUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

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

    public function test_migrates_design_settings_verbatim(): void
    {
        $this->seedConfig('customizing_css', '#logo { color: red; }');
        $this->seedConfig('pc_html_head', '<meta name="x" content="y">');
        $this->seedConfig('pc_html_bottom2', '<script>analytics()</script>');
        $this->seedConfig('footer_before', 'Guest footer');
        $this->seedConfig('footer_after', 'Member footer');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'customizing_css', 'value' => '#logo { color: red; }']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'pc_html_head', 'value' => '<meta name="x" content="y">']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'pc_html_bottom2', 'value' => '<script>analytics()</script>']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_before', 'value' => 'Guest footer']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_after', 'value' => 'Member footer']);
    }

    public function test_migrates_the_policy_bodies_verbatim(): void
    {
        // Verbatim here, Markdown later: the walk copies the text and the post-walk
        // SitePolicyMarkdownTransform is what reformats it.
        $this->seedConfig('user_agreement', "第1条(適用)\n本規約は…");
        $this->seedConfig('privacy_policy', '<h2>取得する情報</h2>');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'user_agreement', 'value' => "第1条(適用)\n本規約は…"]);
        $this->assertDatabaseHas('sns_settings', ['key' => 'privacy_policy', 'value' => '<h2>取得する情報</h2>']);
    }

    public function test_migrates_the_web_public_age_setting(): void
    {
        $this->seedConfig('is_allow_web_public_flag_age', '1');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'allow_web_public_age', 'value' => '1']);
    }

    public function test_migrates_the_web_public_diary_setting(): void
    {
        // Off is the case that matters: OpenPNE 4 defaults this one ON, so an OpenPNE 3 site that
        // disabled web-public diaries would silently regain them if the row did not carry over.
        $this->seedConfig('op_diary_plugin_use_open_diary', '0');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_allow_web_public', 'value' => '0']);
    }

    public function test_migrates_the_board_comment_reply_switches(): void
    {
        $this->seedConfig('op_community_topic_plugin_community_topic_comment_reply', '1');
        $this->seedConfig('op_community_topic_plugin_community_event_comment_reply', '1');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'group_topic_comment_reply', 'value' => '1']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'group_event_comment_reply', 'value' => '1']);
    }

    public function test_migrates_the_posting_switch_and_the_diary_search_settings(): void
    {
        // Off is the case that matters for the two default-on switches: a site that had closed
        // posting or search would silently reopen if the row did not carry over.
        $this->seedConfig('is_allow_post_activity', '0');
        $this->seedConfig('op_diary_plugin_search_enable', '0');
        $this->seedConfig('op_diary_plugin_search_period_enable', '1');
        $this->seedConfig('op_diary_plugin_search_period', '7');

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'timeline_posting_enabled', 'value' => '0']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_search_enabled', 'value' => '0']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_search_period_enabled', 'value' => '1']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_search_period_days', 'value' => '7']);
    }

    public function test_rewrites_the_profile_policy_codes_and_copies_an_unknown_one_verbatim(): void
    {
        foreach (['', '0', '1', '4', '9'] as $code) {
            DB::table('sns_config')->delete();
            DB::table('sns_settings')->whereIn('key', ['profile_visibility_policy', 'sns_name'])->delete();
            $this->seedConfig('is_allow_config_public_flag_profile_page', $code);
            // The sibling verbatim key proves the value CASE leaves every other row untouched.
            $this->seedConfig('sns_name', 'Verbatim '.$code);

            $this->runUpgrade();

            $expected = ['' => 'member_choice', '0' => 'member_choice', '1' => 'members', '4' => 'web', '9' => '9'][$code];
            $this->assertDatabaseHas('sns_settings', ['key' => 'profile_visibility_policy', 'value' => $expected]);
            $this->assertDatabaseHas('sns_settings', ['key' => 'sns_name', 'value' => 'Verbatim '.$code]);
        }
    }

    public function test_a_trailing_space_keeps_a_code_out_of_the_map(): void
    {
        // PAD SPACE would equate these with '', '0' and '4'; OpenPNE 3 read the first two as truthy (members-only) and '4 ' as the web, which this deliberately closes.
        foreach ([' ', '0 ', '4 '] as $code) {
            DB::table('sns_config')->delete();
            DB::table('sns_settings')->where('key', 'profile_visibility_policy')->delete();
            $this->seedConfig('is_allow_config_public_flag_profile_page', $code);

            $this->runUpgrade();

            $this->assertSame($code, DB::table('sns_settings')->where('key', 'profile_visibility_policy')->value('value'));
        }
    }

    public function test_does_not_migrate_security_or_unknown_keys(): void
    {
        $this->seedConfig('is_use_captcha', '0');   // security key — excluded so it cannot weaken the fail-closed default
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
