<?php

namespace Tests\Feature\Upgrade\Community;

use App\Features\Community\CommunityRole;
use App\Features\Community\JoinPolicy;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityCategoryUpgrade;
use App\Upgrade\Steps\CommunityJoinRequestUpgrade;
use App\Upgrade\Steps\CommunityMemberUpgrade;
use App\Upgrade\Steps\CommunityUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled community steps against the real OpenPNE 3 DDL, checking the KV config flattens
 * onto typed columns, the position rows fold into the role, the dropped category root nulls its
 * references, and the is_pre flag splits community_member into confirmed members / pending requests.
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class CommunityUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    /** Source tables this step set reads, created from the real dump (FKs stripped to stand alone). */
    private array $sourceTables = [
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
        [$admin, $sub, $member, $applicant] = Member::factory()->count(4)->create()->all();

        // Synthetic root (lft=1, never selectable) + two real children, one admin-only.
        $this->seedCategory(1, 'ROOT', allow: 1, lft: 1);
        $this->seedCategory(2, 'Sports', allow: 1, lft: 2);
        $this->seedCategory(3, 'Staff only', allow: 0, lft: 3);

        // Community 100: closed (approval), described, in a real category.
        $this->seedCommunity(100, 'Tokyo Runners', categoryId: 2, createdAt: '2017-08-09 10:11:12');
        $this->seedConfig(100, 'register_policy', 'close');
        $this->seedConfig(100, 'description', 'We run on weekends.');
        // Community 101: no config (→ Open default), and points at the dropped root → category nulled.
        $this->seedCommunity(101, 'Osaka Cooks', categoryId: 1);

        // Confirmed members of 100 with their position rows; one pending applicant.
        $this->seedCommunityMember(1000, 100, $admin->id, isPre: 0);
        $this->seedCommunityMember(1001, 100, $sub->id, isPre: 0);
        $this->seedCommunityMember(1002, 100, $member->id, isPre: 0);
        $this->seedCommunityMember(1003, 100, $applicant->id, isPre: 1);
        $this->seedPosition(1, 100, $admin->id, communityMemberId: 1000, name: 'admin');
        $this->seedPosition(2, 100, $sub->id, communityMemberId: 1001, name: 'sub_admin');
        // A pending admin-transfer target: not a role, lands in communities.pending_admin_member_id.
        $this->seedPosition(3, 100, $member->id, communityMemberId: 1002, name: 'admin_confirm');

        $this->runUpgrade(new CommunityCategoryUpgrade);
        $this->runUpgrade(new CommunityUpgrade);
        $this->runUpgrade(new CommunityMemberUpgrade);
        $this->runUpgrade(new CommunityJoinRequestUpgrade);

        // Root dropped; the two real children kept with their creation flag intact.
        $this->assertDatabaseCount('community_categories', 2);
        $this->assertDatabaseMissing('community_categories', ['id' => 1]);
        $this->assertDatabaseHas('community_categories', ['id' => 2, 'name' => 'Sports', 'is_allow_member_community' => 1, 'parent_id' => null]);
        $this->assertDatabaseHas('community_categories', ['id' => 3, 'is_allow_member_community' => 0]);

        // Config flattened; pending admin captured; image deferred to its null default.
        $this->assertDatabaseHas('communities', [
            'id' => 100,
            'name' => 'Tokyo Runners',
            'description' => 'We run on weekends.',
            'register_policy' => JoinPolicy::Approval->value,
            'community_category_id' => 2,
            'pending_admin_member_id' => $member->id,
            'file_id' => null,
            'created_at' => '2017-08-09 10:11:12',
        ]);
        // Missing register_policy → Open; reference to the dropped root → null category.
        $this->assertDatabaseHas('communities', [
            'id' => 101,
            'register_policy' => JoinPolicy::Open->value,
            'description' => null,
            'community_category_id' => null,
            'pending_admin_member_id' => null,
        ]);

        // Positions folded into role; only confirmed (is_pre=0) members land here.
        $this->assertDatabaseCount('community_members', 3);
        $this->assertDatabaseHas('community_members', ['community_id' => 100, 'member_id' => $admin->id, 'role' => CommunityRole::Admin->value]);
        $this->assertDatabaseHas('community_members', ['community_id' => 100, 'member_id' => $sub->id, 'role' => CommunityRole::SubAdmin->value]);
        $this->assertDatabaseHas('community_members', ['community_id' => 100, 'member_id' => $member->id, 'role' => CommunityRole::Member->value]);

        // The is_pre=1 row is a join request, not a member.
        $this->assertDatabaseCount('community_join_requests', 1);
        $this->assertDatabaseHas('community_join_requests', ['community_id' => 100, 'member_id' => $applicant->id]);
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

    private function seedCommunity(int $id, string $name, ?int $categoryId, string $createdAt = '2016-02-02 00:00:00'): void
    {
        DB::table('community')->insert([
            'id' => $id,
            'name' => $name,
            'file_id' => null,
            'community_category_id' => $categoryId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function seedConfig(int $communityId, string $name, string $value): void
    {
        DB::table('community_config')->insert([
            'community_id' => $communityId,
            'name' => $name,
            'value' => $value,
            'created_at' => '2016-02-02 00:00:00',
            'updated_at' => '2016-02-02 00:00:00',
        ]);
    }

    private function seedCommunityMember(int $id, int $communityId, int $memberId, int $isPre): void
    {
        DB::table('community_member')->insert([
            'id' => $id,
            'community_id' => $communityId,
            'member_id' => $memberId,
            'is_pre' => $isPre,
            'is_receive_mail_pc' => 0,
            'is_receive_mail_mobile' => 0,
            'created_at' => '2016-03-03 00:00:00',
            'updated_at' => '2016-03-03 00:00:00',
        ]);
    }

    private function seedPosition(int $id, int $communityId, int $memberId, int $communityMemberId, string $name): void
    {
        DB::table('community_member_position')->insert([
            'id' => $id,
            'community_id' => $communityId,
            'member_id' => $memberId,
            'community_member_id' => $communityMemberId,
            'name' => $name,
            'created_at' => '2016-03-03 00:00:00',
            'updated_at' => '2016-03-03 00:00:00',
        ]);
    }
}
