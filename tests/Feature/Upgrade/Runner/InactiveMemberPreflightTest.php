<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\SourcePreflight;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\DirectMessageUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\GroupCategoryUpgrade;
use App\Upgrade\Steps\GroupUpgrade;
use App\Upgrade\Steps\MemberNotificationSettingUpgrade;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The preflight's member-reference checks, each pinned as counted over the rows that actually reach
 * a target column, so a deliberately unmigrated row is not an abort. DatabaseMigrations, not
 * MigratesUpgradeTargetsOnce: a run that migrates files rewires the file_bin FK, which mutates the
 * app schema.
 */
class InactiveMemberPreflightTest extends TestCase
{
    use DatabaseMigrations;

    private const SOURCE_TABLES = ['member', 'diary', 'diary_image', 'member_relationship', 'message', 'message_type',
        'message_send_list', 'deleted_message', 'community', 'community_config', 'community_category',
        'community_member', 'community_member_position', 'member_config'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The preflight introspects information_schema and the runner executes on MySQL.');
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

    public function test_content_authored_by_a_member_who_never_activated_aborts_before_any_write(): void
    {
        // Stock OpenPNE 3 cannot produce this — an inactive account holds no SNSMember credential —
        // so the upgrade refuses rather than guessing whether to drop the diary and its comments.
        $this->createSources('member', 'diary', 'diary_image');
        $this->seedMember(1, isActive: 0);
        $this->seedDiary(10, memberId: 1);

        [$ok, $output] = $this->runSteps([new DiaryUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::inactiveMemberReferenceMessage('diary.member_id', 1), $output);
        $this->assertDatabaseCount('diaries', 0);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_content_authored_by_an_activated_member_passes(): void
    {
        $this->createSources('member', 'diary', 'diary_image');
        $this->seedMember(1, isActive: 1);
        Member::factory()->create(['id' => 1]); // MemberUpgrade's output, which diaries.member_id references
        $this->seedDiary(10, memberId: 1);

        [$ok, $output] = $this->runSteps([new DiaryUpgrade]);

        $this->assertTrue($ok, $output);
        $this->assertDatabaseCount('diaries', 1);
    }

    public function test_a_rows_own_step_filter_scopes_the_count(): void
    {
        // The friend/community message types are not migrated, so counting every `message` row would
        // abort on data the upgrade never reads.
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessageType(2, 'friend_link');
        $this->seedMessage(10, memberId: 1, typeId: 2); // notification, not migrated

        [$ok, $output] = $this->runSteps([new DirectMessageUpgrade]);

        $this->assertTrue($ok, $output);
        $this->assertStringNotContainsString('message.member_id', $output);
    }

    public function test_a_personal_message_from_an_inactive_sender_still_aborts(): void
    {
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessage(10, memberId: 1, typeId: 1);

        [$ok, $output] = $this->runSteps([new DirectMessageUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::inactiveMemberReferenceMessage('message.member_id', 1), $output);
    }

    public function test_a_send_list_row_is_counted_through_its_parent_message_not_the_from_step_filter(): void
    {
        // A draft's recipient reaches direct_messages.draft_recipient_id by correlated subquery, so the
        // sent-only filter of DirectMessageRecipientUpgrade is the wrong set to count over.
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessage(10, memberId: 1, typeId: 1, isSend: 0); // a draft
        DB::table('message_send_list')->insert(['id' => 1, 'message_id' => 10, 'member_id' => 2, 'is_read' => 0,
            'is_deleted' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);

        [$ok, $output] = $this->runSteps([new DirectMessageUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::inactiveMemberReferenceMessage('message_send_list.member_id', 1), $output);
    }

    public function test_only_the_position_row_that_becomes_a_target_column_is_counted(): void
    {
        // community_member_position feeds groups.pending_admin_member_id from its admin_confirm
        // row alone; the other names are read for the community role, which carries no member id.
        $this->createGroupSources();
        $this->seedMember(1, isActive: 0);
        $this->seedGroup(100);
        $this->seedPosition(1, groupId: 100, memberId: 1, name: 'sub_admin_confirm');

        [$ok, $output] = $this->runSteps([new GroupCategoryUpgrade, new GroupUpgrade]);

        $this->assertTrue($ok, $output);

        $this->seedPosition(2, groupId: 100, memberId: 1, name: 'admin_confirm');

        [$ok, $output] = $this->runSteps([new GroupCategoryUpgrade, new GroupUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(
            SourcePreflight::inactiveMemberReferenceMessage('community_member_position.member_id', 1),
            $output,
        );
    }

    public function test_a_draft_send_list_row_the_step_discards_is_not_an_abort(): void
    {
        // Only the lowest-id send-list row is folded onto draft_recipient_id, so refusing over a
        // discarded duplicate would contradict the step.
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessage(10, memberId: 1, typeId: 1, isSend: 0);
        $this->seedSendListRow(1, messageId: 10, memberId: 1); // selected (lowest id), activated
        $this->seedSendListRow(2, messageId: 10, memberId: 2); // discarded duplicate, inactive
        Member::factory()->create(['id' => 1]); // MemberUpgrade's output, which the message references

        [$ok, $output] = $this->runSteps([new DirectMessageUpgrade]);

        $this->assertTrue($ok, $output);
    }

    public function test_the_draft_send_list_row_the_step_reads_is_an_abort(): void
    {
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessage(10, memberId: 1, typeId: 1, isSend: 0);
        $this->seedSendListRow(1, messageId: 10, memberId: 2); // selected, inactive
        $this->seedSendListRow(2, messageId: 10, memberId: 1);

        [$ok, $output] = $this->runSteps([new DirectMessageUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::inactiveMemberReferenceMessage('message_send_list.member_id', 1), $output);
    }

    public function test_a_send_list_row_under_an_out_of_range_is_send_is_not_an_abort(): void
    {
        // is_send is a bare tinyint with no CHECK, and a third value puts no member id anywhere (folded
        // only at 0, receipts only at 1), so the row migrates as a recipient-less draft without a refusal.
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        Member::factory()->create(['id' => 1]);
        $this->seedMessageType(1, 'message');
        $this->seedMessage(10, memberId: 1, typeId: 1, isSend: 2);
        $this->seedSendListRow(1, messageId: 10, memberId: 2); // would be the selected row, inactive

        [$ok, $output] = $this->runSteps([new DirectMessageUpgrade]);

        $this->assertTrue($ok, $output);
        $this->assertDatabaseHas('direct_messages', ['id' => 10, 'draft_recipient_id' => null]);
    }

    public function test_a_superseded_admin_confirm_row_is_not_an_abort(): void
    {
        // (community_member_id, name) is the UNIQUE, so a community can hold several admin_confirm
        // rows and GroupUpgrade reads only the latest.
        $this->createGroupSources();
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        $this->seedGroup(100);
        $this->seedPosition(1, groupId: 100, memberId: 2, name: 'admin_confirm'); // superseded, inactive
        $this->seedPosition(2, groupId: 100, memberId: 1, name: 'admin_confirm'); // latest, activated
        Member::factory()->create(['id' => 1]); // MemberUpgrade's output, which pending_admin_member_id references

        [$ok, $output] = $this->runSteps([new GroupCategoryUpgrade, new GroupUpgrade]);

        $this->assertTrue($ok, $output);
    }

    public function test_the_latest_admin_confirm_row_is_an_abort(): void
    {
        $this->createGroupSources();
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        $this->seedGroup(100);
        $this->seedPosition(1, groupId: 100, memberId: 1, name: 'admin_confirm');
        $this->seedPosition(2, groupId: 100, memberId: 2, name: 'admin_confirm'); // latest, inactive

        [$ok, $output] = $this->runSteps([new GroupCategoryUpgrade, new GroupUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(
            SourcePreflight::inactiveMemberReferenceMessage('community_member_position.member_id', 1),
            $output,
        );
    }

    public function test_a_refuse_only_subset_still_verifies_the_columns_its_check_reads(): void
    {
        // No step in this subset selects FROM `member`, so only the refusal count reaches is_active;
        // it must abort with the column message rather than throw inside the count.
        $this->createSources('member', 'diary', 'diary_image');
        DB::statement('ALTER TABLE `member` DROP COLUMN `is_active`');

        [$ok, $output] = $this->runSteps([new DiaryUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::missingColumnMessage('member', 'is_active'), $output);
    }

    public function test_a_subquery_only_refuse_table_verifies_the_columns_its_scope_reads(): void
    {
        // community_member_position is no step's FROM, so neither its member_id nor the `name` the
        // scope narrows by is attributed to any step's consumed columns.
        $this->createGroupSources();
        DB::statement('ALTER TABLE `community_member_position` DROP COLUMN `name`');

        [$ok, $output] = $this->runSteps([new GroupCategoryUpgrade, new GroupUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::missingColumnMessage('community_member_position', 'name'), $output);
    }

    public function test_dangling_rows_are_counted_across_every_step_reading_the_column(): void
    {
        // member_config is guarded by both the preference and the notification-settings steps with
        // different name filters, so counting one step's slice would under-report the total.
        $this->createSources('member', 'member_config');
        $this->seedConfig(1, memberId: 999, name: 'diary_public_flag');              // MemberPreferenceUpgrade
        $this->seedConfig(2, memberId: 999, name: 'is_send_pc_diaryReplyPost_mail'); // MemberNotificationSettingUpgrade

        [$ok, $output] = $this->runSteps([new MemberPreferenceUpgrade, new MemberNotificationSettingUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(
            SourcePreflight::danglingMemberReferenceMessage('member_config.member_id', 2),
            $output,
        );
    }

    public function test_a_guarded_tables_inactive_reference_is_dropped_not_refused(): void
    {
        // The registration artifacts are the expected case: the guard drops them and the run proceeds.
        $this->createSources('member', 'member_relationship');
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        $this->seedRelationship(1, 2);

        [$ok, $output] = $this->runSteps([new FriendshipUpgrade]);

        $this->assertTrue($ok, $output);
        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_a_guarded_reference_to_a_member_missing_from_the_source_aborts(): void
    {
        // The guard would swallow this as "not activated"; an absent member is a broken dump, so it
        // gets its own refusal rather than vanishing into the drop.
        $this->createSources('member', 'member_relationship');
        $this->seedMember(1, isActive: 1);
        $this->seedRelationship(1, 999); // no member 999

        [$ok, $output] = $this->runSteps([new FriendshipUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(
            SourcePreflight::danglingMemberReferenceMessage('member_relationship.member_id_to', 1),
            $output,
        );
        $this->assertDatabaseCount('friendships', 0);
    }

    private function createGroupSources(): void
    {
        $this->createSources('member', 'community', 'community_config', 'community_category',
            'community_member', 'community_member_position');
    }

    private function seedSendListRow(int $id, int $messageId, int $memberId): void
    {
        DB::table('message_send_list')->insert(['id' => $id, 'message_id' => $messageId, 'member_id' => $memberId,
            'is_read' => 0, 'is_deleted' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedConfig(int $id, int $memberId, string $name): void
    {
        DB::table('member_config')->insert(['id' => $id, 'member_id' => $memberId, 'name' => $name, 'value' => '1',
            'name_value_hash' => md5($name.'1'), 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function createSources(string ...$tables): void
    {
        foreach ($tables as $table) {
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    private function dropSources(): void
    {
        foreach (array_reverse(self::SOURCE_TABLES) as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private function seedMember(int $id, int $isActive): void
    {
        DB::table('member')->insert(['id' => $id, 'name' => "Member {$id}", 'is_login_rejected' => 0,
            'is_active' => $isActive, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedDiary(int $id, int $memberId): void
    {
        DB::table('diary')->insert(['id' => $id, 'member_id' => $memberId, 'title' => 'T', 'body' => 'B',
            'public_flag' => 1, 'is_open' => 1, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedMessageType(int $id, string $name): void
    {
        DB::table('message_type')->insert(['id' => $id, 'type_name' => $name, 'is_deleted' => 0,
            'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedMessage(int $id, int $memberId, int $typeId, int $isSend = 1): void
    {
        DB::table('message')->insert(['id' => $id, 'member_id' => $memberId, 'message_type_id' => $typeId,
            'subject' => 'S', 'body' => 'B', 'foreign_id' => 0, 'return_message_id' => 0, 'thread_message_id' => 0,
            'is_send' => $isSend, 'is_deleted' => 0,
            'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedGroup(int $id): void
    {
        DB::table('community')->insert(['id' => $id, 'name' => 'C', 'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedPosition(int $id, int $groupId, int $memberId, string $name): void
    {
        DB::table('community_member_position')->insert(['id' => $id, 'community_id' => $groupId,
            'member_id' => $memberId, 'community_member_id' => $id, 'name' => $name,
            'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedRelationship(int $from, int $to): void
    {
        DB::table('member_relationship')->insert(['member_id_from' => $from, 'member_id_to' => $to,
            'is_friend' => 1, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    /**
     * @param  list<UpgradeStep>  $steps
     * @return array{0: bool, 1: string}
     */
    private function runSteps(array $steps, ?RunOptions $options = null): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, $steps))->run(
            $options ?? new RunOptions,
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        return [$ok, implode("\n", $lines)];
    }
}
