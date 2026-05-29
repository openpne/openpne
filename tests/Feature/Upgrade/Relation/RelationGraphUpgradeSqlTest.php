<?php

namespace Tests\Feature\Upgrade\Relation;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled member_relationship steps against the real OpenPNE 3 DDL, checking the
 * single source table decomposes into friendships / friend_requests / member_blocks by flag.
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class RelationGraphUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        DB::statement(SourceSchema::default()->createStatement('member_relationship', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        }

        parent::tearDown();
    }

    public function test_decomposes_member_relationship_into_three_tables_by_flag(): void
    {
        [$a, $b, $c, $d, $e, $f] = Member::factory()->count(6)->create()->all();

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
        $a = Member::factory()->create();
        $b = Member::factory()->create();
        $this->seedRelationship($a, $b, ['is_friend' => 1, 'created_at' => '2017-08-09 10:11:12']);

        $this->runUpgrade(new FriendshipUpgrade);

        $this->assertDatabaseHas('friendships', [
            'member_id' => $a->id,
            'friend_id' => $b->id,
            'created_at' => '2017-08-09 10:11:12',
        ]);
    }

    private function runUpgrade(UpgradeStep $step): void
    {
        DB::statement((new InsertSelectCompiler)->compile($step));
    }

    private function seedRelationship(Member $from, Member $to, array $flags = []): void
    {
        DB::table('member_relationship')->insert(array_merge([
            'member_id_from' => $from->id,
            'member_id_to' => $to->id,
            'is_friend' => null,
            'is_friend_pre' => null,
            'is_access_block' => null,
            'created_at' => '2018-01-02 03:04:05',
            'updated_at' => '2018-01-02 03:04:05',
        ], $flags));
    }
}
