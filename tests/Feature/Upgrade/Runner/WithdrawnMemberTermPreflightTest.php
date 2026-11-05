<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Runner\WithdrawnMemberTermPreflight;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\WithdrawnMemberTermJaUpgrade;
use App\Upgrade\Steps\WithdrawnMemberTermUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A withdrawn-member nickname wider than the term column aborts before any write. */
class WithdrawnMemberTermPreflightTest extends TestCase
{
    use DatabaseMigrations;

    /** `plugin` is read beside `sns_config` by the theme pass, so the structural preflight requires it. */
    private const SOURCE_TABLES = ['sns_config', 'plugin'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The preflight reads a qualified source table and the runner executes on MySQL.');
        }

        $this->dropSourceTables();
        foreach (self::SOURCE_TABLES as $table) {
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

    public function test_the_widest_value_the_column_holds_passes(): void
    {
        $this->seedConfig(str_repeat('あ', 255));

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok, $output);
        $this->assertStringNotContainsString('ERROR', $output);
    }

    public function test_a_value_wider_than_the_column_aborts(): void
    {
        $this->seedConfig(str_repeat('あ', 256));

        [$ok, $output] = $this->preflight();

        $this->assertFalse($ok);
        $this->assertStringContainsString('ERROR '.WithdrawnMemberTermPreflight::tooLongMessage(256), $output);
        $this->assertStringContainsString('Aborted', $output);
    }

    /** @return array{0: bool, 1: string} */
    private function preflight(): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new WithdrawnMemberTermJaUpgrade]))->run(
            new RunOptions(dryRun: true),
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        return [$ok, implode("\n", $lines)];
    }

    private function dropSourceTables(): void
    {
        foreach (self::SOURCE_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private function seedConfig(string $value): void
    {
        DB::table('sns_config')->insert(['name' => WithdrawnMemberTermUpgrade::SOURCE_NAME, 'value' => $value]);
    }
}
