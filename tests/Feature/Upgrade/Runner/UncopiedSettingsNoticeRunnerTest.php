<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Support\SnsSettingKey;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\RunReport;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FriendFeatureUpgrade;
use App\Upgrade\Steps\SnsSettingUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The notice reads a source table only the structural verdict guarantees, so the cases that matter are
 * the runs that must not read it at all: a subset without an sns_config step, and a source missing
 * the table, which has to abort the way it always did.
 */
class UncopiedSettingsNoticeRunnerTest extends TestCase
{
    use DatabaseMigrations;

    private const PREFIX = 'op3_';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The notice reads a qualified source table and the runner executes on MySQL.');
        }

        $this->dropSourceTables();
        $this->createSourceTable('');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceTables();
        }

        parent::tearDown();
    }

    public function test_the_openpne3_size_is_reported_with_the_env_value_to_set(): void
    {
        $this->seedSize('300K');

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade]);

        $this->assertTrue($ok);
        $this->assertStringContainsString(
            'WARN sns_config image_max_filesize = 300K is not copied; to keep it, set OPENPNE_IMAGE_MAX_UPLOAD_KB=300 in .env (per file, kilobytes).',
            $output,
        );
    }

    public function test_a_size_that_cannot_be_read_is_reported_as_such(): void
    {
        $this->seedSize('abc');

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade]);

        $this->assertTrue($ok, 'an unreadable value is a warning, not an abort');
        $this->assertStringContainsString('image_max_filesize = abc is not copied and could not be read as a size; set OPENPNE_IMAGE_MAX_UPLOAD_KB yourself', $output);
    }

    public function test_no_row_reports_nothing(): void
    {
        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade]);

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('image_max_filesize', $output);
    }

    public function test_a_null_size_reports_nothing(): void
    {
        $this->seedSize(null);

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade]);

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('image_max_filesize', $output);
    }

    public function test_a_real_run_reports_it_too(): void
    {
        $this->seedSize('2M');

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade], dryRun: false);

        $this->assertTrue($ok);
        $this->assertStringContainsString('OPENPNE_IMAGE_MAX_UPLOAD_KB=2048', $output);
    }

    public function test_a_null_value_of_a_migrated_setting_is_reported_with_the_default_that_applies(): void
    {
        DB::table('sns_config')->insert(['name' => 'is_allow_web_public_flag_age', 'value' => null]);

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade], dryRun: false);

        $this->assertTrue($ok);
        $this->assertStringContainsString(
            "WARN sns_config `is_allow_web_public_flag_age` is NULL and is not copied: OpenPNE 3 read its default there, and `allow_web_public_age` keeps its default here ('0').",
            $output,
        );
        $this->assertDatabaseMissing('sns_settings', ['key' => 'allow_web_public_age']);
    }

    public function test_a_hand_edited_spelling_the_source_collation_still_matches_is_reported(): void
    {
        // Case, a trailing space and a full-width letter are all one name under utf8mb3_unicode_ci.
        DB::table('sns_config')->insert(['name' => 'Ｉs_Allow_Web_Public_Flag_Age ', 'value' => null]);

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade], dryRun: false);

        $this->assertTrue($ok);
        $this->assertStringContainsString('sns_config `Ｉs_Allow_Web_Public_Flag_Age ` is NULL and is not copied', $output);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'allow_web_public_age']);
    }

    public function test_every_migrated_setting_with_a_null_value_is_copied_or_reported(): void
    {
        // The notice's name list and the step's filter are built apart from the same disposition; a key
        // one has and the other lacks would be dropped in silence.
        $keys = array_filter(SnsSettingKey::cases(), static fn (SnsSettingKey $key): bool => $key->isMigratedFromOp3());
        foreach ($keys as $key) {
            DB::table('sns_config')->insert(['name' => $key->op3SourceName(), 'value' => null]);
        }

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade], dryRun: false);

        $this->assertTrue($ok);
        foreach ($keys as $key) {
            $notice = "sns_config `{$key->op3SourceName()}` is NULL";
            if ($key->op3NullValueIsKept()) {
                $this->assertDatabaseHas('sns_settings', ['key' => $key->value, 'value' => '']);
                $this->assertStringNotContainsString($notice, $output, "{$key->op3SourceName()} is copied as empty, not reported");
            } else {
                $this->assertDatabaseMissing('sns_settings', ['key' => $key->value]);
                $this->assertStringContainsString($notice, $output, "{$key->op3SourceName()} is left out and must be reported");
            }
        }
    }

    public function test_a_null_value_of_a_setting_never_copied_is_not_reported(): void
    {
        DB::table('sns_config')->insert(['name' => 'enable_pc', 'value' => null]);

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade], dryRun: false);

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('enable_pc', $output);
    }

    public function test_the_command_prints_a_value_that_looks_like_a_console_tag(): void
    {
        // An unknown colour in an unescaped `<fg=…>` makes the console formatter throw.
        $this->seedSize('<fg=bogus>300K');
        $this->app->instance(UpgradeRunner::class, new UpgradeRunner(new InsertSelectCompiler, [new SnsSettingUpgrade]));

        $this->artisan('openpne:upgrade-from-3', ['--dry-run' => true])
            ->expectsOutputToContain('image_max_filesize = <fg=bogus>300K is not copied')
            ->assertSuccessful();
    }

    public function test_the_env_value_is_also_a_machine_readable_hint(): void
    {
        $this->seedSize('1M');
        $report = new RunReport(function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, dryRun: true);

        $this->assertTrue((new UpgradeRunner(new InsertSelectCompiler, [new SnsSettingUpgrade]))->run(new RunOptions(dryRun: true), null, $report));

        $this->assertContains('ENV_HINT OPENPNE_IMAGE_MAX_UPLOAD_KB=1024', $lines);
        $this->assertSame(['OPENPNE_IMAGE_MAX_UPLOAD_KB' => '1024'], (array) $report->jsonSerialize()['env_hints']);
    }

    public function test_an_unreadable_size_warns_without_a_hint(): void
    {
        $this->seedSize('1X');
        $report = new RunReport(dryRun: true);

        $this->assertTrue((new UpgradeRunner(new InsertSelectCompiler, [new SnsSettingUpgrade]))->run(new RunOptions(dryRun: true), null, $report));

        $json = $report->jsonSerialize();
        $this->assertSame([], (array) $json['env_hints']);
        $this->assertStringContainsString('could not be read as a size', $json['warnings'][0]['message']);
    }

    public function test_the_json_report_carries_a_console_tag_verbatim(): void
    {
        // Raw output: the formatter would throw on the unknown colour, as the human run's escaping shows.
        $this->seedSize('<fg=bogus>300K');
        $this->app->instance(UpgradeRunner::class, new UpgradeRunner(new InsertSelectCompiler, [new SnsSettingUpgrade]));

        $this->assertSame(0, Artisan::call('openpne:upgrade-from-3', ['--dry-run' => true, '--json' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($report['dry_run']);
        $this->assertStringContainsString('image_max_filesize = <fg=bogus>300K is not copied', $report['warnings'][0]['message']);
        $this->assertStringNotContainsString('WARN', $output);
    }

    public function test_any_step_reading_sns_config_carries_the_notice(): void
    {
        $this->seedSize('300K');

        [$ok, $output] = $this->upgrade([new FriendFeatureUpgrade]);

        $this->assertTrue($ok);
        $this->assertStringContainsString('OPENPNE_IMAGE_MAX_UPLOAD_KB=300', $output);
    }

    public function test_a_run_without_an_sns_config_step_does_not_read_the_table(): void
    {
        $this->dropSourceTables();

        [$ok, $output] = $this->upgrade([]);

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('image_max_filesize', $output);
    }

    public function test_a_source_missing_sns_config_still_aborts_gracefully(): void
    {
        $this->dropSourceTables();

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('Aborted', $output);
        $this->assertStringNotContainsString('image_max_filesize', $output);
    }

    public function test_the_source_prefix_and_database_are_honoured(): void
    {
        $this->dropSourceTables();
        $this->createSourceTable(self::PREFIX);
        DB::table(self::PREFIX.'sns_config')->insert(['name' => 'image_max_filesize', 'value' => '512K']);

        [$ok, $output] = $this->upgrade(
            [new SnsSettingUpgrade],
            new RunOptions(sourcePrefix: self::PREFIX, sourceDatabase: DB::getDatabaseName(), dryRun: true),
        );

        $this->assertTrue($ok);
        $this->assertStringContainsString('OPENPNE_IMAGE_MAX_UPLOAD_KB=512', $output);
    }

    /**
     * @param  list<UpgradeStep>  $steps
     * @return array{bool, string}
     */
    private function upgrade(array $steps, ?RunOptions $options = null, bool $dryRun = true): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, $steps))->run(
            $options ?? new RunOptions(dryRun: $dryRun),
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        return [$ok, implode("\n", $lines)];
    }

    private function seedSize(?string $value): void
    {
        DB::table('sns_config')->insert(['name' => 'image_max_filesize', 'value' => $value]);
    }

    // `plugin` beside it: the preflight requires it of every run reading sns_config (the theme pass).
    private function createSourceTable(string $prefix): void
    {
        foreach (['sns_config', 'plugin'] as $table) {
            $statement = SourceSchema::default()->createStatement($table, withoutForeignKeys: true);

            DB::statement(str_replace("CREATE TABLE `{$table}`", "CREATE TABLE `{$prefix}{$table}`", $statement));
        }
    }

    private function dropSourceTables(): void
    {
        foreach (['sns_config', 'plugin'] as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement('DROP TABLE IF EXISTS `'.self::PREFIX.$table.'`');
        }
    }
}
