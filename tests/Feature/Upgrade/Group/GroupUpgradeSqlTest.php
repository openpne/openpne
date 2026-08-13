<?php

namespace Tests\Feature\Upgrade\Group;

use App\Features\Group\GroupRole;
use App\Features\Group\JoinPolicy;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\GroupCategoryUpgrade;
use App\Upgrade\Steps\GroupJoinRequestUpgrade;
use App\Upgrade\Steps\GroupMemberUpgrade;
use App\Upgrade\Steps\GroupUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/**
 * Runs the compiled community steps against the real OpenPNE 3 DDL, checking the KV config flattens
 * onto typed columns, the position rows fold into the role, the dropped category root nulls its
 * references, and the is_pre flag splits community_member into confirmed members / pending requests.
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class GroupUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceMembers;

    /** Source tables this step set reads, created from the real dump (FKs stripped to stand alone). */
    private array $sourceTables = [
        'member',
        'community_category',
        'community',
        'community_config',
        'community_member',
        'community_member_position',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        foreach ($this->sourceTables as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (array_reverse($this->sourceTables) as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        parent::tearDown();
    }

    public function test_migrates_communities_categories_members_and_requests(): void
    {
        [$admin, $sub, $member, $applicant] = $this->activeMembers(4);

        // Synthetic root (lft=1, never selectable) + two real children, one admin-only.
        $this->seedCategory(1, 'ROOT', allow: 1, lft: 1);
        $this->seedCategory(2, 'Sports', allow: 1, lft: 2);
        $this->seedCategory(3, 'Staff only', allow: 0, lft: 3);

        // Group 100: closed (approval), described, in a real category.
        $this->seedGroup(100, 'Tokyo Runners', categoryId: 2, createdAt: '2017-08-09 10:11:12');
        $this->seedConfig(100, 'register_policy', 'close');
        $this->seedConfig(100, 'description', 'We run on weekends.');
        // Topic board config: members-only read, admin-only posting.
        $this->seedConfig(100, 'public_flag', 'auth_commu_member');
        $this->seedConfig(100, 'topic_authority', 'admin_only');
        // Marked as a default ("everyone") community (OpenPNE 3 KV value '1').
        $this->seedConfig(100, 'is_default', '1');
        // Admin opted out of join notifications (KV '0' → false).
        $this->seedConfig(100, 'is_send_pc_joinCommunity_mail', '0');
        // Group 101: no config (→ Open default), and points at the dropped root → category nulled.
        $this->seedGroup(101, 'Osaka Cooks', categoryId: 1);

        // Confirmed members of 100 with their position rows; one pending applicant.
        $this->seedGroupMember(1000, 100, $admin->id, isPre: 0);
        $this->seedGroupMember(1001, 100, $sub->id, isPre: 0);
        $this->seedGroupMember(1002, 100, $member->id, isPre: 0);
        $this->seedGroupMember(1003, 100, $applicant->id, isPre: 1);
        $this->seedPosition(1, 100, $admin->id, groupMemberId: 1000, name: 'admin');
        $this->seedPosition(2, 100, $sub->id, groupMemberId: 1001, name: 'sub_admin');
        // A pending admin-transfer target: not a role, lands in groups.pending_admin_member_id.
        $this->seedPosition(3, 100, $member->id, groupMemberId: 1002, name: 'admin_confirm');

        $this->runUpgrade(new GroupCategoryUpgrade);
        $this->runUpgrade(new GroupUpgrade);
        $this->runUpgrade(new GroupMemberUpgrade);
        $this->runUpgrade(new GroupJoinRequestUpgrade);

        // Root dropped; the two real children kept with their creation flag intact.
        $this->assertDatabaseCount('group_categories', 2);
        $this->assertDatabaseMissing('group_categories', ['id' => 1]);
        $this->assertDatabaseHas('group_categories', ['id' => 2, 'name' => 'Sports', 'is_allow_member_group' => 1, 'parent_id' => null]);
        $this->assertDatabaseHas('group_categories', ['id' => 3, 'is_allow_member_group' => 0]);

        // Config flattened; pending admin captured; top-image file_id copied verbatim (null here).
        $this->assertDatabaseHas('groups', [
            'id' => 100,
            'name' => 'Tokyo Runners',
            'description' => 'We run on weekends.',
            'register_policy' => JoinPolicy::Approval->value,
            'is_default' => 1,
            'is_join_notification_enabled' => 0,
            'topic_read_access' => TopicReadAccess::MembersOnly->value,
            'topic_post_authority' => TopicPostAuthority::AdminsOnly->value,
            'group_category_id' => 2,
            'pending_admin_member_id' => $member->id,
            'file_id' => null,
            'created_at' => '2017-08-09 10:11:12',
        ]);
        // Missing register_policy → Open and missing topic config → the open defaults; reference to
        // the dropped root → null category.
        $this->assertDatabaseHas('groups', [
            'id' => 101,
            'register_policy' => JoinPolicy::Open->value,
            'is_default' => 0,
            'is_join_notification_enabled' => 1,
            'topic_read_access' => TopicReadAccess::Everyone->value,
            'topic_post_authority' => TopicPostAuthority::Members->value,
            'description' => null,
            'group_category_id' => null,
            'pending_admin_member_id' => null,
        ]);

        // Positions folded into role; only confirmed (is_pre=0) members land here.
        $this->assertDatabaseCount('group_members', 3);
        $this->assertDatabaseHas('group_members', ['group_id' => 100, 'member_id' => $admin->id, 'role' => GroupRole::Admin->value]);
        $this->assertDatabaseHas('group_members', ['group_id' => 100, 'member_id' => $sub->id, 'role' => GroupRole::SubAdmin->value]);
        $this->assertDatabaseHas('group_members', ['group_id' => 100, 'member_id' => $member->id, 'role' => GroupRole::Member->value]);

        // The is_pre=1 row is a join request, not a member.
        $this->assertDatabaseCount('group_join_requests', 1);
        $this->assertDatabaseHas('group_join_requests', ['group_id' => 100, 'member_id' => $applicant->id]);
    }

    public function test_preserves_the_top_image_file_id(): void
    {
        // FileUpgrade keeps file.id, so the community's top-image link resolves; GroupUpgrade
        // carries it onto groups.file_id (FileUpgrade assigns the file its `community` owner).
        DB::table('files')->insert([
            'id' => 42,
            'name' => 'community_top_token',
            'type' => 'image/png',
            'byte_size' => 256,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
        $this->seedGroup(120, 'Photo Club', categoryId: null, fileId: 42);

        $this->runUpgrade(new GroupUpgrade);

        $this->assertDatabaseHas('groups', ['id' => 120, 'file_id' => 42]);
    }

    public function test_default_community_rows_for_a_member_who_never_activated_are_dropped(): void
    {
        // OpenPNE 3's register form joins the default groups in the same save that writes the
        // profile — one request before activation — so an abandoned signup leaves a membership row
        // behind. Both halves of the is_pre split must drop it.
        $joined = $this->activeMember();
        $abandoned = $this->inactiveSourceMember(9100);

        $this->seedCategory(1, 'General', 1, 1);
        $this->seedGroup(100, 'Default', 1);
        $this->seedGroupMember(1000, 100, $joined->id, isPre: 0);
        $this->seedGroupMember(1001, 100, $abandoned, isPre: 0);
        $this->seedGroupMember(1002, 100, $abandoned, isPre: 1);

        $this->runUpgrade(new GroupCategoryUpgrade);
        $this->runUpgrade(new GroupUpgrade);
        $this->runUpgrade(new GroupMemberUpgrade);
        $this->runUpgrade(new GroupJoinRequestUpgrade);

        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseHas('group_members', ['group_id' => 100, 'member_id' => $joined->id]);
        $this->assertDatabaseCount('group_join_requests', 0);
    }

    private function runUpgrade(UpgradeStep $step): void
    {
        DB::statement((new InsertSelectCompiler)->compile($step));
    }

    private function seedCategory(int $id, string $name, int $allow, int $lft): void
    {
        DB::table('community_category')->insert([
            'id' => $id,
            'name' => $name,
            'is_allow_member_community' => $allow,
            'lft' => $lft,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function seedGroup(int $id, string $name, ?int $categoryId, ?int $fileId = null, string $createdAt = '2016-02-02 00:00:00'): void
    {
        DB::table('community')->insert([
            'id' => $id,
            'name' => $name,
            'file_id' => $fileId,
            'community_category_id' => $categoryId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function seedConfig(int $groupId, string $name, string $value): void
    {
        DB::table('community_config')->insert([
            'community_id' => $groupId,
            'name' => $name,
            'value' => $value,
            'created_at' => '2016-02-02 00:00:00',
            'updated_at' => '2016-02-02 00:00:00',
        ]);
    }

    private function seedGroupMember(int $id, int $groupId, int $memberId, int $isPre): void
    {
        DB::table('community_member')->insert([
            'id' => $id,
            'community_id' => $groupId,
            'member_id' => $memberId,
            'is_pre' => $isPre,
            'is_receive_mail_pc' => 0,
            'is_receive_mail_mobile' => 0,
            'created_at' => '2016-03-03 00:00:00',
            'updated_at' => '2016-03-03 00:00:00',
        ]);
    }

    private function seedPosition(int $id, int $groupId, int $memberId, int $groupMemberId, string $name): void
    {
        DB::table('community_member_position')->insert([
            'id' => $id,
            'community_id' => $groupId,
            'member_id' => $memberId,
            'community_member_id' => $groupMemberId,
            'name' => $name,
            'created_at' => '2016-03-03 00:00:00',
            'updated_at' => '2016-03-03 00:00:00',
        ]);
    }
}
