<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\SourcePreflight;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityCategoryUpgrade;
use App\Upgrade\Steps\CommunityUpgrade;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MessageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The preflight's member-reference checks: content the upgrade will not migrate for a member
 * OpenPNE 3 never activated, and references to a member missing from the source altogether. Both
 * abort before the first write; what makes them useful rather than noisy is that each is counted
 * over the rows that actually reach a target column, so a deliberately-unmigrated row is not an
 * abort (the point of ActiveMember::references()'s scopes).
 *
 * DatabaseMigrations, not MigratesUpgradeTargetsOnce: the runner rewires the file_bin FK when a run
 * migrates files, which mutates the app schema.
 */
class InactiveMemberPreflightTest extends TestCase
{
    use DatabaseMigrations;

    private const SOURCE_TABLES = ['member', 'diary', 'diary_image', 'member_relationship', 'message', 'message_type',
        'message_send_list', 'deleted_message', 'community', 'community_config', 'community_category',
        'community_member', 'community_member_position'];

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
        // The friend/community message types are OpenPNE 3's notification mechanism and are not
        // migrated. Counting every `message` row would abort on data the upgrade never reads.
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessageType(2, 'friend_link');
        $this->seedMessage(10, memberId: 1, typeId: 2); // notification, not migrated

        [$ok, $output] = $this->runSteps([new MessageUpgrade]);

        $this->assertTrue($ok, $output);
        $this->assertStringNotContainsString('message.member_id', $output);
    }

    public function test_a_personal_message_from_an_inactive_sender_still_aborts(): void
    {
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessage(10, memberId: 1, typeId: 1);

        [$ok, $output] = $this->runSteps([new MessageUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::inactiveMemberReferenceMessage('message.member_id', 1), $output);
    }

    public function test_a_send_list_row_is_counted_through_its_parent_message_not_the_from_step_filter(): void
    {
        // A draft's recipient reaches messages.draft_recipient_id by correlated subquery, so the
        // sent-only filter of MessageRecipientUpgrade is the wrong set to count over.
        $this->createSources('member', 'message', 'message_type', 'message_send_list', 'deleted_message');
        $this->seedMember(1, isActive: 1);
        $this->seedMember(2, isActive: 0);
        $this->seedMessageType(1, 'message');
        $this->seedMessage(10, memberId: 1, typeId: 1, isSend: 0); // a draft
        DB::table('message_send_list')->insert(['id' => 1, 'message_id' => 10, 'member_id' => 2, 'is_read' => 0,
            'is_deleted' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);

        [$ok, $output] = $this->runSteps([new MessageUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::inactiveMemberReferenceMessage('message_send_list.member_id', 1), $output);
    }

    public function test_only_the_position_row_that_becomes_a_target_column_is_counted(): void
    {
        // community_member_position feeds communities.pending_admin_member_id from its admin_confirm
        // row alone; the other names are read for the community role, which carries no member id.
        $this->createSources('member', 'community', 'community_config', 'community_category',
            'community_member', 'community_member_position');
        $this->seedMember(1, isActive: 0);
        $this->seedCommunity(100);
        $this->seedPosition(1, communityId: 100, memberId: 1, name: 'sub_admin_confirm');

        [$ok, $output] = $this->runSteps([new CommunityCategoryUpgrade, new CommunityUpgrade]);

        $this->assertTrue($ok, $output);

        $this->seedPosition(2, communityId: 100, memberId: 1, name: 'admin_confirm');

        [$ok, $output] = $this->runSteps([new CommunityCategoryUpgrade, new CommunityUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(
            SourcePreflight::inactiveMemberReferenceMessage('community_member_position.member_id', 1),
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

    private function seedCommunity(int $id): void
    {
        DB::table('community')->insert(['id' => $id, 'name' => 'C', 'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedPosition(int $id, int $communityId, int $memberId, string $name): void
    {
        DB::table('community_member_position')->insert(['id' => $id, 'community_id' => $communityId,
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
