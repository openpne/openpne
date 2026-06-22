<?php

namespace Tests\Feature\Upgrade\Member;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MemberImageUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled `member_image` → `member_images` INSERT...SELECT against the real OpenPNE 3 DDL,
 * checking the single-avatar collapse: one row per member (member_images.member_id is unique), the
 * primary kept, else the lowest id, the rest dropped.
 *
 * MySQL only: the set-based copy, the source DDL and the correlated filter are MySQL features.
 */
class MemberImageUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        DB::statement('DROP TABLE IF EXISTS `member_image`');
        DB::statement(SourceSchema::default()->createStatement('member_image', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `member_image`');
        }

        parent::tearDown();
    }

    public function test_keeps_the_primary_image_and_drops_the_others(): void
    {
        $member = Member::factory()->create();
        $this->seedFile(1);
        $this->seedFile(2);
        $this->seedFile(3);
        $this->seedMemberImage(100, $member->id, 1, isPrimary: null);
        $this->seedMemberImage(101, $member->id, 2, isPrimary: 1);
        $this->seedMemberImage(102, $member->id, 3, isPrimary: null);

        $this->runUpgrade();

        $this->assertDatabaseCount('member_images', 1);
        $this->assertDatabaseHas('member_images', ['member_id' => $member->id, 'file_id' => 2]);
    }

    public function test_ties_among_equal_rank_break_by_lowest_id(): void
    {
        $member = Member::factory()->create();
        $this->seedFile(4);
        $this->seedFile(5);
        $this->seedMemberImage(11, $member->id, 5, isPrimary: null); // higher id, dropped
        $this->seedMemberImage(10, $member->id, 4, isPrimary: null); // lowest id, kept

        $this->runUpgrade();

        $this->assertDatabaseCount('member_images', 1);
        $this->assertDatabaseHas('member_images', ['member_id' => $member->id, 'file_id' => 4]);
    }

    public function test_a_demoted_image_outranks_a_never_primary_one(): void
    {
        // OpenPNE 3's Member::getImage() orders by is_primary DESC, so a demoted 0 (was the main image,
        // changeMainImage sets the old one false) outranks a never-primary NULL even at a higher id.
        $member = Member::factory()->create();
        $this->seedFile(8);
        $this->seedFile(9);
        $this->seedMemberImage(30, $member->id, 8, isPrimary: null); // never primary, lower id
        $this->seedMemberImage(31, $member->id, 9, isPrimary: 0);     // demoted, higher id, kept

        $this->runUpgrade();

        $this->assertDatabaseCount('member_images', 1);
        $this->assertDatabaseHas('member_images', ['member_id' => $member->id, 'file_id' => 9]);
    }

    public function test_one_avatar_per_member_across_members(): void
    {
        [$a, $b] = Member::factory()->count(2)->create()->all();
        $this->seedFile(6);
        $this->seedFile(7);
        $this->seedMemberImage(200, $a->id, 6, isPrimary: 1);
        $this->seedMemberImage(201, $b->id, 7, isPrimary: 1);

        $this->runUpgrade();

        $this->assertDatabaseCount('member_images', 2);
        $this->assertDatabaseHas('member_images', ['member_id' => $a->id, 'file_id' => 6]);
        $this->assertDatabaseHas('member_images', ['member_id' => $b->id, 'file_id' => 7]);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new MemberImageUpgrade));
    }

    private function seedFile(int $id): void
    {
        DB::table('files')->insert([
            'id' => $id,
            'name' => "tok_{$id}",
            'type' => 'image/png',
            'byte_size' => 128,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function seedMemberImage(int $id, int $memberId, int $fileId, ?int $isPrimary): void
    {
        DB::table('member_image')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'file_id' => $fileId,
            'is_primary' => $isPrimary,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }
}
