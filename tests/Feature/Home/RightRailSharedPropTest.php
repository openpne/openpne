<?php

namespace Tests\Feature\Home;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RightRailSharedPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_rail_carries_the_members_friends(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('rightRail.people.kind', 'friends')
                ->has('rightRail.people.items', 1)
                ->where('rightRail.people.items.0.id', $friend->getKey())
                ->where('rightRail.people.items.0.href', "/member/{$friend->getKey()}")
            );
    }

    /**
     * The rail's groups grid moved to the sidebar's room list, which is the same memberships ordered
     * by what was last said. Nothing here reads a group, so a joined member changes nothing.
     */
    public function test_rail_carries_no_communities(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->missing('rightRail.joinedGroups'));
    }

    public function test_rail_images_are_sized_for_its_own_tiles_not_the_smaller_avatars_elsewhere(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        MemberImage::factory()->create(['member_id' => $friend->getKey()]);
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);

        // The rail's tiles paint at ~90px, well above every other surface's avatar, so the grid asks
        // for 180 rather than the 120 the small avatars use.
        $expectedFace = $friend->fresh()->avatar->file->thumbnailUrl(180, 180, square: true);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('rightRail.people.items.0.imageUrl', $expectedFace));
    }

    public function test_rail_is_empty_for_a_member_with_no_friends(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('rightRail.people.kind', 'friends')
                ->where('rightRail.people.items', [])
            );
    }

    public function test_rail_is_absent_for_a_guest(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');

        $this->get('/login')->assertInertia(fn ($page) => $page->where('rightRail', null));
    }
}
