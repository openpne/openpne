<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\DiaryUpgrade;
use PHPUnit\Framework\TestCase;

class InsertSelectCompilerTest extends TestCase
{
    public function test_default_uses_bare_table_names(): void
    {
        $sql = (new InsertSelectCompiler)->compile(new DiaryUpgrade);

        $this->assertStringContainsString('INSERT INTO `diaries`', $sql);
        $this->assertStringContainsString('FROM `diary`', $sql);
    }

    public function test_table_prefix_is_concatenated_to_the_name(): void
    {
        $sql = (new InsertSelectCompiler)->compile(new DiaryUpgrade, sourcePrefix: 'op3_');

        $this->assertStringContainsString('FROM `op3_diary`', $sql);
        $this->assertStringContainsString('INSERT INTO `diaries`', $sql);
    }

    public function test_database_qualifies_the_table_separately(): void
    {
        // `db`.`table`, not `db.table` — the different-database workflow.
        $sql = (new InsertSelectCompiler)->compile(
            new DiaryUpgrade,
            sourceDatabase: 'op3db',
            targetDatabase: 'op4db',
        );

        $this->assertStringContainsString('INSERT INTO `op4db`.`diaries`', $sql);
        $this->assertStringContainsString('FROM `op3db`.`diary`', $sql);
    }

    public function test_database_and_prefix_combine(): void
    {
        $sql = (new InsertSelectCompiler)->compile(
            new DiaryUpgrade,
            sourcePrefix: 'op3_',
            sourceDatabase: 'op3db',
        );

        $this->assertStringContainsString('FROM `op3db`.`op3_diary`', $sql);
    }
}
