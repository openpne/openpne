<?php

namespace Tests\Feature\Community;

use App\Features\Community\CommunityMembership;
use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_of_returns_role_for_members_and_null_for_strangers(): void
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create();
        $stranger = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertSame(CommunityRole::Admin, CommunityMembership::roleOf($community, $admin));
        $this->assertNull(CommunityMembership::roleOf($community, $stranger));
        $this->assertTrue(CommunityMembership::isMember($community, $admin));
        $this->assertFalse(CommunityMembership::isMember($community, $stranger));
        $this->assertTrue(CommunityMembership::isAdmin($community, $admin));
        $this->assertTrue(CommunityMembership::canManage($community, $admin));
    }

    public function test_sub_admin_can_manage_but_is_not_admin(): void
    {
        $community = Community::factory()->create();
        $sub = Member::factory()->create();
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->getKey(), 'member_id' => $sub->getKey()]);

        $this->assertTrue(CommunityMembership::canManage($community, $sub));
        $this->assertFalse(CommunityMembership::isAdmin($community, $sub));
    }

    public function test_plain_member_cannot_manage(): void
    {
        $community = Community::factory()->create();
        $member = Member::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFalse(CommunityMembership::canManage($community, $member));
    }

    public function test_is_pending_reads_the_join_request_table(): void
    {
        $community = Community::factory()->create();
        $applicant = Member::factory()->create();

        $this->assertFalse(CommunityMembership::isPending($community, $applicant));

        DB::table('community_join_requests')->insert([
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $this->assertTrue(CommunityMembership::isPending($community, $applicant));
        $this->assertFalse(CommunityMembership::isMember($community, $applicant));
    }
}
