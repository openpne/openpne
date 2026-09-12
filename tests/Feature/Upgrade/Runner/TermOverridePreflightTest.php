<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\TermOverridePreflight;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\TermOverrideUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
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

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok, $output);
        $this->assertStringNotContainsString('ERROR', $output);
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
    private function preflight(): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new TermOverrideUpgrade]))->run(
            new RunOptions(dryRun: true),
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
