<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Column;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\UpgradeStep;
use LogicException;
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

    public function test_no_where_clause_without_a_filter(): void
    {
        $this->assertStringNotContainsString('WHERE', (new InsertSelectCompiler)->compile(new DiaryUpgrade));
    }

    public function test_filter_becomes_a_where_clause(): void
    {
        $sql = (new InsertSelectCompiler)->compile(new FriendshipUpgrade);

        $this->assertStringContainsString('FROM `member_relationship`', $sql);
        $this->assertStringContainsString('WHERE is_friend = 1', $sql);
    }

    public function test_step_with_pending_targets_is_not_compilable(): void
    {
        // A step whose required target columns have no source yet must not silently
        // compile to an INSERT that omits them.
        $step = new class extends UpgradeStep
        {
            protected string $source = 'legacy';

            protected string $target = 'modern';

            public function columns(): array
            {
                return ['id' => Column::source('id')];
            }

            public function pendingTargets(): array
            {
                return ['secret' => 'no source resolved yet'];
            }
        };

        $this->expectException(LogicException::class);
        (new InsertSelectCompiler)->compile($step);
    }
}
