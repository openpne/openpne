<?php

namespace Tests\Feature\Upgrade\Member;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\MemberImageUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * The `member_image` → `member_images` single-avatar collapse: one row per member
 * (member_images.member_id is unique), the row OpenPNE 3's getImage() would show (is_primary DESC,
 * then id), the rest dropped.
 */
class MemberImageUpgradeSqlTest extends UpgradeSqlTestCase
{
    use SeedsSourceMembers;

    protected function sourceTables(): array
    {
        return ['member', 'member_image'];
    }

    public function test_keeps_the_primary_image_and_drops_the_others(): void
    {
        $member = $this->activeMember();
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
        $member = $this->activeMember();
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
        $member = $this->activeMember();
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
        [$a, $b] = $this->activeMembers(2);
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
