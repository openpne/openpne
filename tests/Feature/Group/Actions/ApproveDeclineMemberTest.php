<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\ApproveMember;
use App\Features\Group\Actions\DeclinePendingMember;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\GroupRole;
use App\Features\Group\Events\GroupJoined;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class ApproveDeclineMemberTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    /** @return array{Group, Member, Member} community, admin, applicant */
    private function approvalGroupWithApplicant(): array
    {
        $group = Group::factory()->approval()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $applicant = Member::factory()->create();
        (new JoinGroup)($applicant, $group);

        return [$group, $admin, $applicant];
    }

    public function test_approve_moves_a_request_into_a_confirmed_membership(): void
    {
        Event::fake([GroupJoined::class]);
        [$group, $admin, $applicant] = $this->approvalGroupWithApplicant();

        (new ApproveMember)($admin, $group, $applicant);

        $this->assertDatabaseMissing('group_join_requests', [
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
            'role' => GroupRole::Member->value,
        ]);
        Event::assertDispatched(GroupJoined::class);
    }

    public function test_a_non_admin_cannot_approve(): void
    {
        [$group, , $applicant] = $this->approvalGroupWithApplicant();
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFailsWith(GroupActionFailure::NotAdmin, fn () => (new ApproveMember)($member, $group, $applicant));
        $this->assertDatabaseHas('group_join_requests', ['member_id' => $applicant->getKey()]);
    }

    public function test_approving_a_non_applicant_fails(): void
    {
        [$group, $admin] = $this->approvalGroupWithApplicant();
        $stranger = Member::factory()->create();

        $this->assertFailsWith(GroupActionFailure::NotPending, fn () => (new ApproveMember)($admin, $group, $stranger));
    }

    public function test_decline_removes_the_request_without_creating_a_member(): void
    {
        [$group, $admin, $applicant] = $this->approvalGroupWithApplicant();

        (new DeclinePendingMember)($admin, $group, $applicant);

        $this->assertDatabaseMissing('group_join_requests', ['member_id' => $applicant->getKey()]);
        $this->assertDatabaseMissing('group_members', ['member_id' => $applicant->getKey()]);
    }

    public function test_a_non_admin_cannot_decline(): void
    {
        [$group, , $applicant] = $this->approvalGroupWithApplicant();
        $stranger = Member::factory()->create();

        $this->assertFailsWith(GroupActionFailure::NotAdmin, fn () => (new DeclinePendingMember)($stranger, $group, $applicant));
    }
}
