<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\GroupRole;
use App\Features\Group\Events\GroupJoined;
use App\Features\Group\Events\GroupJoinRequested;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\JoinPolicy;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class JoinGroupTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    public function test_open_community_join_creates_a_confirmed_member(): void
    {
        Event::fake([GroupJoined::class]);
        $group = Group::factory()->create();
        $member = Member::factory()->create();

        (new JoinGroup)($member, $group);

        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member->value,
        ]);
        $this->assertDatabaseCount('group_join_requests', 0);
        Event::assertDispatched(GroupJoined::class);
    }

    public function test_approval_community_join_creates_a_pending_request_not_a_member(): void
    {
        Event::fake([GroupJoinRequested::class]);
        $group = Group::factory()->approval()->create();
        $member = Member::factory()->create();

        (new JoinGroup)($member, $group);

        $this->assertDatabaseHas('group_join_requests', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
        $this->assertDatabaseCount('group_members', 0);
        Event::assertDispatched(GroupJoinRequested::class);
    }

    public function test_existing_member_cannot_join_again(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFailsWith(GroupActionFailure::AlreadyMember, fn () => (new JoinGroup)($member, $group));
    }

    public function test_a_duplicate_request_is_rejected(): void
    {
        $group = Group::factory()->approval()->create();
        $member = Member::factory()->create();
        (new JoinGroup)($member, $group);

        $this->assertFailsWith(GroupActionFailure::AlreadyRequested, fn () => (new JoinGroup)($member, $group));
        $this->assertDatabaseCount('group_join_requests', 1);
    }

    public function test_a_pending_applicant_cannot_open_join_after_the_policy_flips(): void
    {
        $group = Group::factory()->approval()->create();
        $member = Member::factory()->create();
        (new JoinGroup)($member, $group);

        // Admin opens the community while the request is still pending.
        $group->update(['register_policy' => JoinPolicy::Open]);

        $this->assertFailsWith(GroupActionFailure::AlreadyRequested, fn () => (new JoinGroup)($member, $group));
        $this->assertDatabaseCount('group_members', 0);
        $this->assertDatabaseCount('group_join_requests', 1);
    }
}
