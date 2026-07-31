<?php

namespace Tests\Feature\Home;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RightRailSharedPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_rail_carries_the_members_friends_and_communities(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('rightRail.people.kind', 'friends')
                ->has('rightRail.people.items', 1)
                ->where('rightRail.people.items.0.id', $friend->getKey())
                ->where('rightRail.people.items.0.href', "/member/{$friend->getKey()}")
                ->has('rightRail.joinedCommunities', 1)
                ->where('rightRail.joinedCommunities.0.href', "/community/{$community->getKey()}")
            );
    }

    public function test_rail_is_empty_for_a_member_with_no_friends_or_communities(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('rightRail.people.kind', 'friends')
                ->where('rightRail.people.items', [])
                ->where('rightRail.joinedCommunities', [])
            );
    }

    public function test_rail_is_absent_for_a_guest(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');

        $this->get('/login')->assertInertia(fn ($page) => $page->where('rightRail', null));
    }
}
