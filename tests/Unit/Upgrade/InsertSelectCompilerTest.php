<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Column;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FileUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
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

    public function test_source_table_is_aliased_to_its_original_name(): void
    {
        $sql = (new InsertSelectCompiler)->compile(new DiaryUpgrade, sourcePrefix: 'op_', sourceDatabase: 'op3db');

        // The FROM is aliased back to the bare name so a step's subqueries keep referencing it.
        $this->assertStringContainsString('FROM `op3db`.`op_diary` AS `diary`', $sql);
    }

    public function test_correlated_subquery_tables_are_qualified_like_the_from(): void
    {
        // MemberPreferenceUpgrade reads member_config in both its FROM and a correlated MAX() subquery.
        $sql = (new InsertSelectCompiler)->compile(new MemberPreferenceUpgrade, sourcePrefix: 'op_', sourceDatabase: 'op3db');

        $this->assertStringContainsString('FROM `op3db`.`op_member_config` AS `member_config`', $sql);
        $this->assertStringContainsString('FROM `op3db`.`op_member_config` `m2`', $sql);
        // The outer row is still referenced by the bare alias name.
        $this->assertStringContainsString('`member_config`.`member_id`', $sql);
        $this->assertStringNotContainsString('{{src:', $sql);
    }

    public function test_file_upgrade_owner_subqueries_are_qualified(): void
    {
        // FileUpgrade's owner subqueries reference their table by name (column qualifiers), so each is
        // aliased to its original name; the personal-message extra adds message / message_type.
        $sql = (new InsertSelectCompiler)->compile(new FileUpgrade, sourcePrefix: 'op_', sourceDatabase: 'op3db');

        $this->assertStringContainsString('FROM `op3db`.`op_member_image` AS `member_image`', $sql);
        $this->assertStringContainsString('FROM `op3db`.`op_message` `p`', $sql);
        $this->assertStringContainsString('FROM `op3db`.`op_message_type`', $sql);
        $this->assertStringNotContainsString('{{src:', $sql);
    }

    public function test_source_tokens_resolve_to_bare_names_without_a_prefix(): void
    {
        // The default (same-database, empty-prefix) path: tokens collapse to bare names.
        $sql = (new InsertSelectCompiler)->compile(new MemberUpgrade);

        $this->assertStringContainsString('FROM `member` AS `member`', $sql);
        $this->assertStringContainsString('FROM `member_config`', $sql);
        $this->assertStringContainsString('FROM `sns_config`', $sql);
        $this->assertStringNotContainsString('{{src:', $sql);
    }

    public function test_an_unresolved_source_token_is_rejected(): void
    {
        // A malformed token (not a valid table identifier) must not silently survive into the SQL.
        $step = new class extends UpgradeStep
        {
            protected string $source = 'legacy';

            protected string $target = 'modern';

            public function columns(): array
            {
                return ['id' => Column::expr('(SELECT 1 FROM {{src:Bad-Name}})')];
            }
        };

        $this->expectException(LogicException::class);
        (new InsertSelectCompiler)->compile($step);
    }
}
