<?php

namespace Tests\Feature\Upgrade\Relation;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/** Runs the compiled member_relationship steps against the real OpenPNE 3 DDL; MySQL only. */
class RelationGraphUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceMembers;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceMemberTable();

        DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        DB::statement(SourceSchema::default()->createStatement('member_relationship', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceMemberTable();
            DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        }

        parent::tearDown();
    }

    public function test_decomposes_member_relationship_into_three_tables_by_flag(): void
    {
        [$a, $b, $c, $d, $e, $f] = $this->activeMembers(6);

        // Friendship A<->B: OpenPNE 3 stores it as two mirrored is_friend rows.
        $this->seedRelationship($a, $b, ['is_friend' => 1]);
        $this->seedRelationship($b, $a, ['is_friend' => 1]);
        // Pending request C->D.
        $this->seedRelationship($c, $d, ['is_friend_pre' => 1]);
        // Block E->F.
        $this->seedRelationship($e, $f, ['is_access_block' => 1]);

        $this->runUpgrade(new FriendshipUpgrade);
        $this->runUpgrade(new FriendRequestUpgrade);
        $this->runUpgrade(new MemberBlockUpgrade);

        // Friendship lands as a bidirectional mirror; the other flags do not leak in.
        $this->assertDatabaseHas('friendships', ['member_id' => $a->id, 'friend_id' => $b->id]);
        $this->assertDatabaseHas('friendships', ['member_id' => $b->id, 'friend_id' => $a->id]);
        $this->assertDatabaseCount('friendships', 2);

        $this->assertDatabaseHas('friend_requests', ['requester_id' => $c->id, 'target_id' => $d->id]);
        $this->assertDatabaseCount('friend_requests', 1);

        $this->assertDatabaseHas('member_blocks', ['blocker_id' => $e->id, 'blocked_id' => $f->id]);
        $this->assertDatabaseCount('member_blocks', 1);
    }

    public function test_preserves_created_at(): void
    {
        $a = $this->activeMember();
        $b = $this->activeMember();
        $this->seedRelationship($a, $b, ['is_friend' => 1, 'created_at' => '2017-08-09 10:11:12']);

        $this->runUpgrade(new FriendshipUpgrade);

        $this->assertDatabaseHas('friendships', [
            'member_id' => $a->id,
            'friend_id' => $b->id,
            'created_at' => '2017-08-09 10:11:12',
        ]);
    }

    public function test_a_relationship_with_a_member_who_never_activated_is_dropped(): void
    {
        // OpenPNE 3's invite (InviteForm::save) friends the inviter to an invitee who is still an
        // inactive pre-registration; (member_id_to, member_id_from) is unique, so each flag and
        // direction needs its own pair.
        $inviter = $this->activeMember();
        [$friend, $requested, $blocked] = array_map($this->inactiveSourceMember(...), [9001, 9002, 9003]);

        $this->seedRelationshipIds($inviter->id, $friend, ['is_friend' => 1]);
        $this->seedRelationshipIds($friend, $inviter->id, ['is_friend' => 1]);
        $this->seedRelationshipIds($requested, $inviter->id, ['is_friend_pre' => 1]);
        $this->seedRelationshipIds($inviter->id, $blocked, ['is_access_block' => 1]);

        $this->runUpgrade(new FriendshipUpgrade);
        $this->runUpgrade(new FriendRequestUpgrade);
        $this->runUpgrade(new MemberBlockUpgrade);

        $this->assertDatabaseCount('friendships', 0);
        $this->assertDatabaseCount('friend_requests', 0);
        $this->assertDatabaseCount('member_blocks', 0);
    }

    private function runUpgrade(UpgradeStep $step): void
    {
        DB::statement((new InsertSelectCompiler)->compile($step));
    }

    private function seedRelationship(Member $from, Member $to, array $flags = []): void
    {
        $this->seedRelationshipIds($from->id, $to->id, $flags);
    }

    /** @param  array<string, mixed>  $flags */
    private function seedRelationshipIds(int $from, int $to, array $flags = []): void
    {
        DB::table('member_relationship')->insert(array_merge([
            'member_id_from' => $from,
            'member_id_to' => $to,
            'is_friend' => null,
            'is_friend_pre' => null,
            'is_access_block' => null,
            'created_at' => '2018-01-02 03:04:05',
            'updated_at' => '2018-01-02 03:04:05',
        ], $flags));
    }
}
