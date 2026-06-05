<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\ApproveMember;
use App\Features\Community\Actions\DeclinePendingMember;
use App\Features\Community\Actions\JoinCommunity;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\CommunityJoined;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class ApproveDeclineMemberTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    /** @return array{Community, Member, Member} community, admin, applicant */
    private function approvalCommunityWithApplicant(): array
    {
        $community = Community::factory()->approval()->create();
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $applicant = Member::factory()->create();
        (new JoinCommunity)($applicant, $community);

        return [$community, $admin, $applicant];
    }

    public function test_approve_moves_a_request_into_a_confirmed_membership(): void
    {
        Event::fake([CommunityJoined::class]);
        [$community, $admin, $applicant] = $this->approvalCommunityWithApplicant();

        (new ApproveMember)($admin, $community, $applicant);

        $this->assertDatabaseMissing('community_join_requests', [
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
            'role' => CommunityRole::Member->value,
        ]);
        Event::assertDispatched(CommunityJoined::class);
    }

    public function test_a_non_admin_cannot_approve(): void
    {
        [$community, , $applicant] = $this->approvalCommunityWithApplicant();
        $member = Member::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFailsWith(CommunityActionFailure::NotAdmin, fn () => (new ApproveMember)($member, $community, $applicant));
        $this->assertDatabaseHas('community_join_requests', ['member_id' => $applicant->getKey()]);
    }

    public function test_approving_a_non_applicant_fails(): void
    {
        [$community, $admin] = $this->approvalCommunityWithApplicant();
        $stranger = Member::factory()->create();

        $this->assertFailsWith(CommunityActionFailure::NotPending, fn () => (new ApproveMember)($admin, $community, $stranger));
    }

    public function test_decline_removes_the_request_without_creating_a_member(): void
    {
        [$community, $admin, $applicant] = $this->approvalCommunityWithApplicant();

        (new DeclinePendingMember)($admin, $community, $applicant);

        $this->assertDatabaseMissing('community_join_requests', ['member_id' => $applicant->getKey()]);
        $this->assertDatabaseMissing('community_members', ['member_id' => $applicant->getKey()]);
    }

    public function test_a_non_admin_cannot_decline(): void
    {
        [$community, , $applicant] = $this->approvalCommunityWithApplicant();
        $stranger = Member::factory()->create();

        $this->assertFailsWith(CommunityActionFailure::NotAdmin, fn () => (new DeclinePendingMember)($stranger, $community, $applicant));
    }
}
