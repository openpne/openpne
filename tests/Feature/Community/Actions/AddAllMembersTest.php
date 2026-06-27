<?php

declare(strict_types=1);

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\AddAllMembers;
use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddAllMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_non_members_as_plain_members_and_returns_count(): void
    {
        $community = Community::factory()->create();
        $a = Member::factory()->create();
        $b = Member::factory()->create();

        $added = app(AddAllMembers::class)($community);

        $this->assertSame(2, $added);
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $a->getKey(),
            'role' => CommunityRole::Member->value,
        ]);
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $b->getKey(),
        ]);
    }

    public function test_is_idempotent_and_preserves_existing_roles(): void
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
        ]);
        Member::factory()->create(); // one outsider

        $first = app(AddAllMembers::class)($community);
        $second = app(AddAllMembers::class)($community);

        $this->assertSame(1, $first);  // only the outsider was added
        $this->assertSame(0, $second); // re-run adds nothing
        // The existing admin keeps the Admin role (their row was not overwritten).
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
            'role' => CommunityRole::Admin->value,
        ]);
    }

    public function test_clears_pending_join_requests_for_the_community(): void
    {
        $community = Community::factory()->create();
        $applicant = Member::factory()->create();
        DB::table('community_join_requests')->insert([
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
            'created_at' => now(),
        ]);

        app(AddAllMembers::class)($community);

        $this->assertDatabaseMissing('community_join_requests', [
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
    }
}
