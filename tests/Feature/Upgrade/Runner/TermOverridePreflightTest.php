<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Services\TermService;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\SourcePreflight;
use App\Upgrade\Runner\TermOverridePreflight;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\TermOverrideUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The term preflight: the source states the term step's INSERT would fail on mid-run abort before any
 * write, and an unrecognised term name is reported rather than silently dropped.
 */
class TermOverridePreflightTest extends TestCase
{
    use DatabaseMigrations;

    private const SOURCE_TABLES = ['sns_term_translation', 'sns_term'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The preflight reads a qualified source table and the runner executes on MySQL.');
        }

        $this->dropSourceTables();
        foreach (array_reverse(self::SOURCE_TABLES) as $table) {
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceTables();
        }

        parent::tearDown();
    }

    public function test_a_clean_source_passes(): void
    {
        $this->seedTerm('community', ['ja_JP' => 'コミュニティ', 'en' => 'community']);
        $this->seedTerm('nickname', ['ja_JP' => str_repeat('あ', 255)]); // the widest value the column holds

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok, $output);
        $this->assertStringNotContainsString('ERROR', $output);
        $this->assertStringNotContainsString('WARN', $output);
    }

    #[TestWith(['id'])]
    #[TestWith(['name'])]
    #[TestWith(['application'])]
    public function test_a_source_without_a_subquery_read_column_is_a_structural_error(string $column): void
    {
        DB::statement($column === 'id'
            ? 'ALTER TABLE `sns_term` DROP PRIMARY KEY, DROP COLUMN `id`'
            : "ALTER TABLE `sns_term` DROP COLUMN `{$column}`");

        [$ok, $output] = $this->preflight();

        $this->assertFalse($ok);
        $this->assertStringContainsString('ERROR '.SourcePreflight::missingColumnMessage('sns_term', $column), $output);
    }

    /** A cached term map from before the cutover would otherwise serve the defaults for up to an hour. */
    public function test_a_run_clears_the_cached_term_map(): void
    {
        $service = app(TermService::class);
        $service->clearCache();
        $this->assertSame('グループ', $service->getTerms('ja')['community']);
        $this->seedTerm('community', ['ja_JP' => 'サークル']);

        [$ok, $output] = $this->preflight(dryRun: false);

        $this->assertTrue($ok, $output);
        $this->assertSame('サークル', $service->getTerms('ja')['community']);
    }

    public function test_two_pc_rows_with_one_name_abort(): void
    {
        $this->seedTerm('community', ['ja_JP' => 'コミュニティ']);
        $this->seedTerm('community', ['ja_JP' => 'サークル']);

        [$ok, $output] = $this->preflight();

        $this->assertFalse($ok);
        $this->assertStringContainsString('ERROR '.TermOverridePreflight::collisionMessage('community', 'ja', 2), $output);
        $this->assertStringContainsString('Aborted', $output);
    }

    /** Two Doctrine cultures of one term (`ja_JP` and a bare `ja`) fold onto one locale. */
    public function test_two_langs_folding_to_one_locale_abort(): void
    {
        $this->seedTerm('friend', ['ja_JP' => 'フレンド', 'ja' => 'ともだち']);

        [$ok, $output] = $this->preflight();

        $this->assertFalse($ok);
        $this->assertStringContainsString(TermOverridePreflight::collisionMessage('friend', 'ja', 2), $output);
    }

    public function test_a_mobile_duplicate_does_not_count(): void
    {
        $this->seedTerm('community', ['ja_JP' => 'コミュニティ']);
        $this->seedTerm('community', ['ja_JP' => 'ｺﾐｭﾆﾃｨ'], application: 'mobile_frontend');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok, $output);
    }

    public function test_a_value_wider_than_the_column_aborts(): void
    {
        $this->seedTerm('nickname', ['ja_JP' => str_repeat('あ', 256)]);

        [$ok, $output] = $this->preflight();

        $this->assertFalse($ok);
        $this->assertStringContainsString(TermOverridePreflight::tooLongMessage('nickname', 'ja', 256), $output);
    }

    public function test_an_unrecognised_name_is_a_warning(): void
    {
        $this->seedTerm('some_plugin_term', ['ja_JP' => 'x']);

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok, $output);
        $this->assertStringContainsString('WARN source `sns_term` holds 1 row(s) named `some_plugin_term`', $output);
    }

    /** @return array{0: bool, 1: string} */
    private function preflight(bool $dryRun = true): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new TermOverrideUpgrade]))->run(
            new RunOptions(dryRun: $dryRun),
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        return [$ok, implode("\n", $lines)];
    }

    /** @param  array<string, string|null>  $values  lang => value */
    private function seedTerm(string $name, array $values, string $application = 'pc_frontend'): void
    {
        $id = (int) DB::table('sns_term')->insertGetId(['name' => $name, 'application' => $application]);
        foreach ($values as $lang => $value) {
            DB::table('sns_term_translation')->insert(['id' => $id, 'lang' => $lang, 'value' => $value]);
        }
    }

    private function dropSourceTables(): void
    {
        foreach (self::SOURCE_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
