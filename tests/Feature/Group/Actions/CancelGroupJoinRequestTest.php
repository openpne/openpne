<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\CancelGroupJoinRequest;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\Actions\QuitGroup;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\Group\JoinPolicy;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class CancelGroupJoinRequestTest extends TestCase
{
    use AssertsGroupFailure, RefreshDatabase;

    public function test_an_applicant_withdraws_their_own_request(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);
        app(JoinGroup::class)($member, $group);

        app(CancelGroupJoinRequest::class)($member, $group);

        $this->assertFalse(GroupMembership::isPending($group, $member));
    }

    public function test_cancelling_without_a_request_fails_loudly(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);

        $this->assertFailsWith(GroupActionFailure::NotPending, fn () => app(CancelGroupJoinRequest::class)($member, $group));
    }

    public function test_it_touches_only_the_applicants_own_row(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);
        [$mine, $theirs] = Member::factory()->count(2)->create();
        app(JoinGroup::class)($mine, $group);
        app(JoinGroup::class)($theirs, $group);

        app(CancelGroupJoinRequest::class)($mine, $group);

        $this->assertFalse(GroupMembership::isPending($group, $mine));
        $this->assertTrue(GroupMembership::isPending($group, $theirs));
    }

    /** Why this action exists: quitting deletes a membership row, and an applicant has none. */
    public function test_quitting_cannot_stand_in_for_it(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);
        app(JoinGroup::class)($member, $group);

        $this->assertFailsWith(GroupActionFailure::NotMember, fn () => app(QuitGroup::class)($member, $group));
        $this->assertTrue(GroupMembership::isPending($group, $member));
    }
}
