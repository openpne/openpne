<?php

namespace Tests\Feature\Community;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CommunityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_is_allowed_for_admin_and_sub_admin_only(): void
    {
        $community = Community::factory()->create();
        $admin = $this->memberWithRole($community, 'admin');
        $sub = $this->memberWithRole($community, 'subAdmin');
        $member = $this->memberWithRole($community, 'member');
        $stranger = Member::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('update', $community));
        $this->assertTrue(Gate::forUser($sub)->allows('update', $community));
        $this->assertFalse(Gate::forUser($member)->allows('update', $community));
        $this->assertFalse(Gate::forUser($stranger)->allows('update', $community));
    }

    public function test_delete_and_manage_members_are_admin_only(): void
    {
        $community = Community::factory()->create();
        $admin = $this->memberWithRole($community, 'admin');
        $sub = $this->memberWithRole($community, 'subAdmin');

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $community));
        $this->assertTrue(Gate::forUser($admin)->allows('manageMembers', $community));
        $this->assertFalse(Gate::forUser($sub)->allows('delete', $community));
        $this->assertFalse(Gate::forUser($sub)->allows('manageMembers', $community));
    }

    public function test_view_is_allowed_for_any_member(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();

        $this->assertTrue(Gate::forUser($stranger)->allows('view', $community));
    }

    private function memberWithRole(Community $community, string $role): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->{$role}()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $member;
    }
}
