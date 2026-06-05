<?php

namespace Tests\Feature\Community;

use App\Features\Community\CommunityRole;
use App\Features\Community\JoinPolicy;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_policy_and_role_cast_to_enums(): void
    {
        $community = Community::factory()->approval()->create();
        $member = CommunityMember::factory()->admin()->create(['community_id' => $community->getKey()]);

        $this->assertSame(JoinPolicy::Approval, $community->refresh()->register_policy);
        $this->assertSame(CommunityRole::Admin, $member->refresh()->role);
        $this->assertFalse($member->is_pre);
    }

    public function test_relations_resolve(): void
    {
        $category = CommunityCategory::factory()->create();
        $community = Community::factory()->create(['community_category_id' => $category->getKey()]);
        $member = Member::factory()->create();
        $membership = CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $this->assertTrue($community->category->is($category));
        $this->assertTrue($community->members->first()->is($membership));
        $this->assertTrue($membership->community->is($community));
        $this->assertTrue($membership->member->is($member));
        $this->assertTrue($member->communityMemberships->first()->is($membership));
        $this->assertTrue($category->communities->first()->is($community));
    }

    public function test_community_name_is_unique(): void
    {
        Community::factory()->create(['name' => 'Hiking Club']);

        $this->expectException(QueryException::class);
        Community::factory()->create(['name' => 'Hiking Club']);
    }

    public function test_membership_is_unique_per_community_and_member(): void
    {
        $community = Community::factory()->create();
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $this->expectException(QueryException::class);
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_deleting_a_community_cascades_to_memberships(): void
    {
        $membership = CommunityMember::factory()->create();
        $communityId = $membership->community_id;

        $membership->community->delete();

        $this->assertDatabaseMissing('community_members', ['community_id' => $communityId]);
    }
}
