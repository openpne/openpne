<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\SourcePreflight;
use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\CommunityMemberUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MessageRecipientUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * The pure (no-DB) parts of the source preflight: the per-step read-table set and the operator-facing
 * messages. The live introspection / ensure-exists behaviour is on the MySQL lane.
 */
class SourcePreflightTest extends TestCase
{
    public function test_read_source_tables_unions_the_from_and_subquery_tables(): void
    {
        // FROM `community_member` plus the role subquery's `community_member_position`.
        $this->assertSame(['community_member', 'community_member_position'], (new CommunityMemberUpgrade)->readSourceTables());
    }

    public function test_read_source_tables_scans_the_filter_too(): void
    {
        $tables = (new MessageRecipientUpgrade)->readSourceTables();

        $this->assertContains('message_send_list', $tables); // FROM
        $this->assertContains('message', $tables);           // filter() subquery
        $this->assertContains('message_type', $tables);      // filter() subquery
        $this->assertContains('deleted_message', $tables);   // expr subquery
    }

    public function test_read_source_tables_for_a_step_without_subqueries(): void
    {
        $this->assertSame(['member_relationship'], (new FriendshipUpgrade)->readSourceTables());
    }

    public function test_optional_plugin_sources_group_by_plugin_with_a_floor(): void
    {
        $optional = StepRegistry::optionalPluginSources();

        $this->assertSame(['opDiaryPlugin', 'opMessagePlugin', 'opCommunityTopicPlugin'], array_keys($optional));
        $this->assertSame('1.1.1', $optional['opDiaryPlugin']['floor']);
        $this->assertContains('diary_image', $optional['opDiaryPlugin']['tables']);
    }

    public function test_messages_name_the_missing_item_and_the_floor(): void
    {
        $this->assertStringContainsString('`member`.`is_login_rejected`', SourcePreflight::missingColumnMessage('member', 'is_login_rejected'));
        $this->assertStringContainsString('3.6.x', SourcePreflight::missingColumnMessage('member', 'is_login_rejected'));
        $this->assertStringContainsString('`community_member_position`', SourcePreflight::missingTableMessage('community_member_position'));
        $this->assertStringContainsString('1.1.1', SourcePreflight::partialPluginMessage('opDiaryPlugin', '1.1.1', ['diary_image']));
        $this->assertStringContainsString('not installed', SourcePreflight::absentPluginMessage('diary'));
    }
}
