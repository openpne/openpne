<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Models\File;
use App\Models\UpgradeState;
use App\Upgrade\Column;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\SourceSchema;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Check B: file_bin byte integrity (files/file_bin count parity, byte_size == LENGTH(bin), FK rewired).
 * A files-targeting step gates the check on; the source `file` + upgrade-state make Check A pass so the
 * assertions isolate Check B.
 */
class VerifierFileBinTest extends TestCase
{
    use DatabaseMigrations;

    private const BLOB = "\x00\x01\xffbytes\x00payload";

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('file_bin integrity uses LENGTH(bin) on MySQL.');
        }

        DB::statement('DROP TABLE IF EXISTS `file`');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `file`');
        }

        parent::tearDown();
    }

    public function test_intact_file_bin_passes(): void
    {
        $this->seedConsistent();

        [$report, $out] = $this->verify();

        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS file_bin:count', $out);
        $this->assertStringContainsString('PASS file_bin:byte_size', $out);
        $this->assertStringContainsString('PASS file_bin:fk', $out);
    }

    public function test_a_byte_size_mismatch_fails(): void
    {
        $this->seedConsistent();
        DB::table('files')->limit(1)->update(['byte_size' => 9999]);

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL file_bin:byte_size', $out);
    }

    public function test_a_missing_file_bin_row_fails(): void
    {
        $ids = $this->seedConsistent();
        DB::table('file_bin')->where('file_id', $ids[0])->delete();

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL file_bin:count', $out);
    }

    /** @return list<int> */
    private function seedConsistent(): array
    {
        DB::statement(SourceSchema::default()->createStatement('file', withoutForeignKeys: true));

        $ids = File::factory()->count(2)->create()->pluck('id')->all();
        DB::table('files')->update(['byte_size' => strlen(self::BLOB)]);

        foreach ($ids as $id) {
            DB::table('file')->insert([
                'id' => $id, 'name' => "f{$id}", 'type' => 'image/png', 'filesize' => strlen(self::BLOB),
                'created_at' => '2018-01-02 03:04:05', 'updated_at' => '2018-01-02 03:04:05',
            ]);
            DB::table('file_bin')->insert([
                'file_id' => $id, 'bin' => self::BLOB, 'created_at' => '2018-01-02 03:04:05', 'updated_at' => '2018-01-02 03:04:05',
            ]);
        }

        UpgradeState::create([
            'step_key' => class_basename($this->fileStep()),
            'status' => UpgradeState::STATUS_COMPLETED,
            'rows_affected' => count($ids),
        ]);

        return $ids;
    }

    /** @return array{0: VerifyReport, 1: string} */
    private function verify(): array
    {
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, [$this->fileStep()]))
            ->verify(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$report, implode("\n", $lines)];
    }

    private function fileStep(): UpgradeStep
    {
        return new class extends UpgradeStep
        {
            protected string $source = 'file';

            protected string $target = 'files';

            public function columns(): array
            {
                return ['id' => Column::source('id')];
            }
        };
    }
}
