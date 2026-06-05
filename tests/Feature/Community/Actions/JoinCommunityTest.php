<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\JoinCommunity;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\CommunityJoined;
use App\Features\Community\Events\CommunityJoinRequested;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class JoinCommunityTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    public function test_open_community_join_creates_a_confirmed_member(): void
    {
        Event::fake([CommunityJoined::class]);
        $community = Community::factory()->create();
        $member = Member::factory()->create();

        (new JoinCommunity)($member, $community);

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => CommunityRole::Member->value,
        ]);
        $this->assertDatabaseCount('community_join_requests', 0);
        Event::assertDispatched(CommunityJoined::class);
    }

    public function test_approval_community_join_creates_a_pending_request_not_a_member(): void
    {
        Event::fake([CommunityJoinRequested::class]);
        $community = Community::factory()->approval()->create();
        $member = Member::factory()->create();

        (new JoinCommunity)($member, $community);

        $this->assertDatabaseHas('community_join_requests', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
        $this->assertDatabaseCount('community_members', 0);
        Event::assertDispatched(CommunityJoinRequested::class);
    }

    public function test_existing_member_cannot_join_again(): void
    {
        $community = Community::factory()->create();
        $member = Member::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFailsWith(CommunityActionFailure::AlreadyMember, fn () => (new JoinCommunity)($member, $community));
    }

    public function test_a_duplicate_request_is_rejected(): void
    {
        $community = Community::factory()->approval()->create();
        $member = Member::factory()->create();
        (new JoinCommunity)($member, $community);

        $this->assertFailsWith(CommunityActionFailure::AlreadyRequested, fn () => (new JoinCommunity)($member, $community));
        $this->assertDatabaseCount('community_join_requests', 1);
    }
}
