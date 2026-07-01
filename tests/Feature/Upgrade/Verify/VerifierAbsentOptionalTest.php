<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\SourcePreflight;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryImageUpgrade;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * verify handles the runner's source-preflight semantics: an uninstalled optional plugin (its source
 * table absent by design) is a clean 0==0==0, and a partial / missing-required source is a reported
 * failure — never a SQL exception on the missing table.
 */
class VerifierAbsentOptionalTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('verify introspects the source on MySQL.');
        }

        $this->dropSources();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSources();
        }

        parent::tearDown();
    }

    public function test_an_uninstalled_optional_plugin_passes(): void
    {
        // opDiary not installed: `diary` is absent. The runner ensure-exists'd an empty diary, ran 0
        // rows, and dropped it — a legitimate completed state with target 0.
        (new UpgradeRunner(new InsertSelectCompiler, [new DiaryUpgrade]))->run(new RunOptions);

        [$report, $out] = $this->verify([new DiaryUpgrade]);

        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS DiaryUpgrade', $out);
    }

    public function test_a_partial_plugin_group_is_reported_not_thrown(): void
    {
        // opDiary present (`diary`) but missing `diary_image` — an old/corrupt plugin. verify must report
        // it, not throw on the missing table.
        DB::statement(SourceSchema::default()->createStatement('diary', withoutForeignKeys: true));

        [$report, $out] = $this->verify([new DiaryUpgrade, new DiaryImageUpgrade]);

        $this->assertTrue($report->failed());
        $this->assertStringContainsString(SourcePreflight::partialPluginMessage('opDiaryPlugin', '1.1.1', ['diary_image']), $out);
    }

    /**
     * @param  list<UpgradeStep>  $steps
     * @return array{0: VerifyReport, 1: string}
     */
    private function verify(array $steps): array
    {
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, $steps))
            ->verify(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$report, implode("\n", $lines)];
    }

    private function dropSources(): void
    {
        foreach (['diary', 'diary_image'] as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
