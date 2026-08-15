<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\Group\GroupMembership;
use App\Features\Group\JoinPolicy;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\Feature;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * An owner giving one of their AI accounts a group seat, and taking it back.
 *
 * The account joins as itself, so its membership is its own: it outlives the owner leaving the same
 * group, and only these endpoints give it up. That independence is the contract these tests pin.
 */
class AiAccountGroupsTest extends TestCase
{
    use RefreshDatabase;

    private Member $owner;

    private Member $aiAccount;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
        $this->owner = Member::factory()->create();
        $this->aiAccount = Member::factory()->aiAccount($this->owner)->create();
    }

    private function join(Group $group): TestResponse
    {
        return $this->actingAs($this->owner)->post("/member/config/ai/{$this->aiAccount->getKey()}/groups/{$group->getKey()}/join");
    }

    private function quit(Group $group): TestResponse
    {
        return $this->actingAs($this->owner)->post("/member/config/ai/{$this->aiAccount->getKey()}/groups/{$group->getKey()}/quit");
    }

    private function cancel(Group $group): TestResponse
    {
        return $this->actingAs($this->owner)->post("/member/config/ai/{$this->aiAccount->getKey()}/groups/{$group->getKey()}/cancel");
    }

    private function showPage(): TestResponse
    {
        return $this->actingAs($this->owner)->get("/member/config/ai/{$this->aiAccount->getKey()}");
    }

    public function test_an_open_group_takes_the_account_in_at_once(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);

        $this->join($group)
            ->assertRedirect(route('member.config.ai.show', ['member' => $this->aiAccount->getKey()]))
            ->assertSessionHas('status', __('This AI account has joined the %community%.'));

        $this->assertTrue(GroupMembership::isMember($group, $this->aiAccount));
        $this->showPage()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groups.joined.0.id', $group->getKey())
            ->where('groups.joinedIds', [$group->getKey()]));
    }

    public function test_an_approval_group_takes_an_application_the_owner_can_withdraw(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);

        $this->join($group)->assertSessionHas('status', __('Join request sent for this AI account.'));

        $this->assertFalse(GroupMembership::isMember($group, $this->aiAccount));
        $this->assertTrue(GroupMembership::isPending($group, $this->aiAccount));
        $this->showPage()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groups.pending.0.id', $group->getKey())
            ->where('groups.pendingIds', [$group->getKey()]));

        $this->cancel($group)->assertSessionHas('status', __('Join request cancelled.'));
        $this->assertFalse(GroupMembership::isPending($group, $this->aiAccount));
    }

    public function test_cancelling_nothing_says_so(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);

        $this->cancel($group)->assertSessionHas('error', __('No pending request found.'));
    }

    /**
     * A group that flips Approval → Anyone-can-join leaves the old request standing, and JoinGroup
     * refuses to hold both a membership and a request. Current behaviour, pinned: the way out is the
     * cancel button, which is what the refusal tells the owner.
     */
    public function test_a_request_left_over_from_approval_blocks_joining_until_it_is_cancelled(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);
        $this->join($group);

        $group->forceFill(['register_policy' => JoinPolicy::Open])->save();

        $this->join($group)->assertSessionHas('error', __('This AI account has already applied to that %community%. Cancel the request first.'));
        $this->assertFalse(GroupMembership::isMember($group, $this->aiAccount));

        $this->cancel($group);
        $this->join($group)->assertSessionHas('status', __('This AI account has joined the %community%.'));
        $this->assertTrue(GroupMembership::isMember($group, $this->aiAccount));
    }

    public function test_leaving_gives_the_seat_up(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);
        $this->join($group);

        $this->quit($group)->assertSessionHas('status', __('This AI account has left the %community%.'));

        $this->assertFalse(GroupMembership::isMember($group, $this->aiAccount));
    }

    public function test_leaving_a_group_the_account_is_not_in_says_so(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);

        $this->quit($group)->assertSessionHas('error', __('This AI account is not in that %community%.'));
    }

    public function test_the_sole_admin_cannot_walk_out_of_its_own_group(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $this->aiAccount->getKey()]);

        $this->quit($group)->assertSessionHas('error', __('This AI account administers that %community%. Transfer the role before leaving.'));

        $this->assertTrue(GroupMembership::isAdmin($group, $this->aiAccount));
    }

    public function test_the_accounts_seat_survives_its_owner_leaving_the_same_group(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);
        // Someone else administers it, so both of these are plain members who may leave.
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => Member::factory()->create()->getKey()]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $this->owner->getKey()]);
        $this->join($group);

        $this->actingAs($this->owner)->post("/groups/{$group->getKey()}/quit", ['id' => $group->getKey()]);

        $this->assertFalse(GroupMembership::isMember($group, $this->owner));
        $this->assertTrue(GroupMembership::isMember($group, $this->aiAccount));
        // And the owner can still act on it from here, having left themselves.
        $this->quit($group)->assertSessionHas('status');
        $this->assertFalse(GroupMembership::isMember($group, $this->aiAccount));
    }

    public function test_a_pending_request_survives_its_owner_leaving_the_same_group(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => Member::factory()->create()->getKey()]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $this->owner->getKey()]);
        $this->join($group);

        $this->actingAs($this->owner)->post("/groups/{$group->getKey()}/quit", ['id' => $group->getKey()]);

        $this->assertTrue(GroupMembership::isPending($group, $this->aiAccount));
        $this->cancel($group)->assertSessionHas('status');
    }

    public function test_someone_elses_account_cannot_be_moved_between_groups(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);
        $theirs = Member::factory()->aiAccount()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $theirs->getKey()]);

        foreach (['join', 'quit', 'cancel'] as $verb) {
            $this->actingAs($this->owner)
                ->post("/member/config/ai/{$theirs->getKey()}/groups/{$group->getKey()}/{$verb}")
                ->assertNotFound();
        }

        $this->assertTrue(GroupMembership::isMember($group, $theirs));
    }

    public function test_the_group_panels_and_their_endpoints_go_with_the_unit(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);
        $this->setSnsSetting(Feature::Group->settingKey(), false);

        $this->showPage()->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('member/config/ai/show')->missing('groups'));

        $this->join($group)->assertNotFound();
        $this->quit($group)->assertNotFound();
        $this->cancel($group)->assertNotFound();
    }

    public function test_a_malformed_keyword_is_a_search_for_nothing_not_a_crash(): void
    {
        Group::factory()->create(['register_policy' => JoinPolicy::Open]);

        $this->actingAs($this->owner)
            ->get("/member/config/ai/{$this->aiAccount->getKey()}?keyword[]=x")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups.keyword', ''));
    }

    public function test_the_management_paths_keep_working_after_the_site_stops_offering_ai_accounts(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, false);

        $this->join($group)->assertSessionHas('status');
        $this->quit($group)->assertSessionHas('status');
    }
}
