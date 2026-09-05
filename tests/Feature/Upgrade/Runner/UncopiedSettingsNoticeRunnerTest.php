<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\SnsSettingUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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

    public function test_a_real_run_reports_it_too(): void
    {
        $this->seedSize('2M');

        [$ok, $output] = $this->upgrade([new SnsSettingUpgrade], dryRun: false);

        $this->assertTrue($ok);
        $this->assertStringContainsString('OPENPNE_IMAGE_MAX_UPLOAD_KB=2048', $output);
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

    private function seedSize(string $value): void
    {
        DB::table('sns_config')->insert(['name' => 'image_max_filesize', 'value' => $value]);
    }

    private function createSourceTable(string $prefix): void
    {
        $statement = SourceSchema::default()->createStatement('sns_config', withoutForeignKeys: true);

        DB::statement(str_replace('CREATE TABLE `sns_config`', "CREATE TABLE `{$prefix}sns_config`", $statement));
    }

    private function dropSourceTables(): void
    {
        DB::statement('DROP TABLE IF EXISTS `sns_config`');
        DB::statement('DROP TABLE IF EXISTS `'.self::PREFIX.'sns_config`');
    }
}
